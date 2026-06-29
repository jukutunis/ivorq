<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Events\InventoryReceiptPosted;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryReceiptRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;

class ReceiptService
{
    public function __construct(
        private InventoryReceiptRepository $receiptRepository,
        private StockMovementService $stockMovementService,
        private InventoryItemRepository $itemRepository,
        private InventoryStockRepository $stockRepository,
        private AvcoValuationCalculator $avcoCalculator,
        private CostAuthorityEnrollmentRepository $enrollmentRepository,
    ) {}

    public function create(array $data): InventoryReceipt
    {
        $data['status'] = ReceiptStatusEnum::Draft->value;
        return $this->receiptRepository->create($data);
    }

    public function post(string $id, ?string $userId = null): InventoryReceipt
    {
        $receipt = $this->receiptRepository->find($id);

        if (! $receipt->status->canTransitionTo(ReceiptStatusEnum::Posted)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition receipt from {$receipt->status->label()} to Posted."],
            ]);
        }

        if ($receipt->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['A receipt must have at least one line before it can be posted.'],
            ]);
        }

        // Enrollment guard: classify line items
        $propertyId = $receipt->property_id;
        $lines = $receipt->lines;
        $totalLines = $lines->count();
        $enrolledCount = 0;
        foreach ($lines as $line) {
            if ($this->enrollmentRepository->hasEnrolledGroupForPropertyItem($propertyId, (string) $line->item_id)) {
                $enrolledCount++;
            }
        }

        // Mixed enrollment fail closed
        if ($enrolledCount > 0 && $enrolledCount < $totalLines) {
            throw new RuntimeException("Mixed enrollment status detected across receipt lines. Fail closed.");
        }

        $allEnrolled = ($enrolledCount === $totalLines);

        if (!$allEnrolled) {
            // Legacy path remains unchanged
            DB::transaction(function () use ($receipt, $userId) {
                $linesByItem = $receipt->lines->groupBy('item_id');

                // Lock and read old state for AVCO
                $itemSnapshots = [];
                foreach ($linesByItem as $itemId => $lines) {
                    $item   = $this->itemRepository->find($itemId);
                    $oldWac = (float) $item->weighted_average_cost;
                    $oldQty = (float) $this->stockRepository->totalQuantityForItemLocked($itemId);

                    $itemSnapshots[$itemId] = [
                        'item'   => $item,
                        'oldWac' => $oldWac,
                        'oldQty' => $oldQty,
                    ];
                }

                // Post movements into Ledger
                foreach ($receipt->lines as $line) {
                    $this->stockMovementService->receive(
                        $receipt->property_id,
                        $line->item_id,
                        $line->location_id,
                        (string) $line->quantity,
                        (string) $line->unit_cost,
                        $receipt->id,
                        $receipt->receipt_number,
                        $userId
                    );
                }

                // Compute AVCO exactly to formula via pure calculator service
                foreach ($linesByItem as $itemId => $lines) {
                    ['item' => $item, 'oldWac' => $oldWac, 'oldQty' => $oldQty] = $itemSnapshots[$itemId];

                    $receiptQty   = $lines->sum(fn ($l) => (float) $l->quantity);
                    $receiptValue = $lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_cost);

                    $newWac = $this->avcoCalculator->calculate($oldQty, $oldWac, $receiptQty, $receiptValue);

                    $this->itemRepository->update($item->id, ['weighted_average_cost' => $newWac]);
                }

                $this->receiptRepository->update($receipt->id, [
                    'status'    => ReceiptStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $userId ?? auth()->id(),
                ]);
            });
        } else {
            // All ENROLLED path
            DB::transaction(function () use ($receipt, $userId, $propertyId) {
                // Resolve open business date
                $businessDate = \Modules\Foundation\Property\Models\PropertyBusinessDate::where('property_id', $propertyId)
                    ->where('status', \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open)
                    ->where('is_open', true)
                    ->first();

                if (!$businessDate) {
                    throw new RuntimeException("No open business date found for property.");
                }

                // Deadlock safety: sort lines deterministically
                $sortedLines = $receipt->lines->sortBy([
                    ['item_id', 'asc'],
                    ['location_id', 'asc'],
                ]);

                $occurredAt = \Illuminate\Support\Carbon::now()->startOfSecond();
                $invocationService = app(\Modules\Finance\CostControl\Services\ControlledReceiptValuationInvocationService::class);

                foreach ($sortedLines as $line) {
                    $intent = new \Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent(
                        propertyId: $propertyId,
                        itemId: $line->item_id,
                        locationId: $line->location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'inventory_receipt',
                        sourceDocumentId: $receipt->id,
                        sourceLineType: 'inventory_receipt_line',
                        sourceLineId: $line->id,
                        movementRole: \Modules\Operations\Inventory\Enums\TransactionTypeEnum::PurchaseReceipt->value,
                        idempotencyKey: (string) $line->id,
                        transactionType: \Modules\Operations\Inventory\Enums\TransactionTypeEnum::PurchaseReceipt,
                        quantityChange: (string) $line->quantity,
                        unitCost: (string) $line->unit_cost,
                        totalCost: (string) ($line->quantity * $line->unit_cost),
                        reference: $receipt->id,
                        notes: 'Controlled Receipt Posting'
                    );

                    $invocationService->invokeReceipt($propertyId, $line->location_id, $line->item_id, $intent, $userId);
                }

                // Update status
                $this->receiptRepository->update($receipt->id, [
                    'status'    => ReceiptStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $userId ?? auth()->id(),
                ]);
            });
        }

        $postedReceipt = $this->receiptRepository->find($id);

        InventoryReceiptPosted::dispatch($postedReceipt);

        return $postedReceipt;
    }
}

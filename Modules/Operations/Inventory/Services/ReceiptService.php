<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Events\InventoryReceiptPosted;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryReceiptRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;

class ReceiptService
{
    public function __construct(
        private InventoryReceiptRepository $receiptRepository,
        private StockMovementService $stockMovementService,
        private InventoryItemRepository $itemRepository,
        private InventoryStockRepository $stockRepository,
        private AvcoValuationCalculator $avcoCalculator,
        private CostDeliveryModePort $costDeliveryMode,
        private InventoryPostingControlCoordinator $postingCoordinator,
        private SynchronousCostValuationPort $synchronousValuation,
    ) {}

    public function create(array $data): InventoryReceipt
    {
        return DB::transaction(function () use ($data): InventoryReceipt {
            $this->lockMutationItems((string) $data['property_id'], collect($data['lines'] ?? [])->pluck('item_id')->all());
            $data['status'] = ReceiptStatusEnum::Draft->value;

            return $this->receiptRepository->create($data);
        });
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
            if ($this->costDeliveryMode->isEnrolled($propertyId, (string) $line->item_id)) {
                $enrolledCount++;
            }
        }

        // Mixed enrollment fail closed
        if ($enrolledCount > 0 && $enrolledCount < $totalLines) {
            throw new RuntimeException('Mixed enrollment status detected across receipt lines. Fail closed.');
        }

        $allEnrolled = ($enrolledCount === $totalLines);

        if (! $allEnrolled) {
            // Legacy path remains unchanged
            DB::transaction(function () use ($receipt, $userId) {
                $linesByItem = $receipt->lines->groupBy('item_id');

                // Lock and read old state for AVCO
                $itemSnapshots = [];
                foreach ($linesByItem as $itemId => $lines) {
                    $item = $this->itemRepository->find($itemId);
                    $oldWac = (float) $item->weighted_average_cost;
                    $oldQty = (float) $this->stockRepository->totalQuantityForItemLocked($itemId);

                    $itemSnapshots[$itemId] = [
                        'item' => $item,
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

                    $receiptQty = $lines->sum(fn ($l) => (float) $l->quantity);
                    $receiptValue = $lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_cost);

                    $newWac = $this->avcoCalculator->calculate($oldQty, $oldWac, $receiptQty, $receiptValue);

                    $this->itemRepository->update($item->id, ['weighted_average_cost' => $newWac]);
                }

                $this->receiptRepository->update($receipt->id, [
                    'status' => ReceiptStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $userId ?? auth()->id(),
                ], true);
            });
        } else {
            // All ENROLLED path
            DB::transaction(function () use ($receipt, $userId, $propertyId) {
                // Resolve open business date
                $businessDate = PropertyBusinessDate::where('property_id', $propertyId)
                    ->where('status', PropertyBusinessDateStatusEnum::Open)
                    ->where('is_open', true)
                    ->first();

                if (! $businessDate) {
                    throw new RuntimeException('No open business date found for property.');
                }

                // Deadlock safety: sort lines deterministically
                $sortedLines = $receipt->lines->sortBy([
                    ['item_id', 'asc'],
                    ['location_id', 'asc'],
                ]);

                $occurredAt = Carbon::now()->startOfSecond();
                $intents = [];
                $sources = [];
                foreach ($sortedLines as $line) {
                    $intent = new InventoryLedgerPostingIntent(
                        propertyId: $propertyId,
                        itemId: $line->item_id,
                        locationId: $line->location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'inventory_receipt',
                        sourceDocumentId: $receipt->id,
                        sourceLineType: 'inventory_receipt_line',
                        sourceLineId: $line->id,
                        movementRole: TransactionTypeEnum::PurchaseReceipt->value,
                        idempotencyKey: (string) $line->id,
                        transactionType: TransactionTypeEnum::PurchaseReceipt,
                        quantityChange: (string) $line->quantity,
                        unitCost: (string) $line->unit_cost,
                        totalCost: (string) ($line->quantity * $line->unit_cost),
                        reference: $receipt->id,
                        notes: 'Controlled Receipt Posting'
                    );
                    $intents[] = $intent;
                    $sources[] = [
                        'propertyId' => $propertyId,
                        'itemId' => (string) $line->item_id,
                        'locationId' => (string) $line->location_id,
                        'idempotencyKey' => $intent->idempotencyKey,
                        'sourceDocumentType' => $intent->sourceDocumentType,
                        'sourceDocumentId' => $intent->sourceDocumentId,
                        'sourceLineType' => $intent->sourceLineType,
                        'sourceLineId' => $intent->sourceLineId,
                        'movementRole' => $intent->movementRole,
                        'quantityChange' => $intent->quantityChange,
                        'unitCost' => $intent->unitCost,
                        'totalCost' => $intent->totalCost,
                    ];
                }

                $resolved = $this->postingCoordinator->resolveDocumentDeliveryModes($sources);
                foreach ($intents as $intent) {
                    $resolution = $resolved[$intent->idempotencyKey];
                    $transaction = $this->postingCoordinator->post($intent, $userId, $resolution['decision']);
                    if ($resolution['existing'] === null
                        && $resolution['decision']->outcome === CostDeliveryPostingDecision::SYNCHRONOUS) {
                        $this->synchronousValuation->applyReceipt($transaction->id);
                    }
                }

                // Update status
                $this->receiptRepository->update($receipt->id, [
                    'status' => ReceiptStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $userId ?? auth()->id(),
                ], true);
            });
        }

        $postedReceipt = $this->receiptRepository->find($id);

        InventoryReceiptPosted::dispatch($postedReceipt);

        return $postedReceipt;
    }

    private function lockMutationItems(string $propertyId, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        sort($itemIds, SORT_STRING);
        foreach ($itemIds as $itemId) {
            $this->costDeliveryMode->lockForDocumentMutation($propertyId, $itemId);
        }
    }
}

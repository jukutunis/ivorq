<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        private AvcoValuationCalculator $avcoCalculator
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

        $postedReceipt = $this->receiptRepository->find($id);

        InventoryReceiptPosted::dispatch($postedReceipt);

        return $postedReceipt;
    }
}

<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryReceiptRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;

class ReceiptService
{
    public function __construct(
        private InventoryReceiptRepository $receiptRepository,
        private StockMovementService $stockMovementService,
        private InventoryItemRepository $itemRepository,
        private InventoryStockBalanceRepository $balanceRepository
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

        // BR-031: at least one line required
        if ($receipt->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['A receipt must have at least one line before it can be posted.'],
            ]);
        }

        DB::transaction(function () use ($receipt, $userId) {
            // ----------------------------------------------------------------
            // C-01 + C-03 fix: Aggregate all lines per item BEFORE touching
            // balances or WAC.  Then lock and read qty_on_hand for each unique
            // item exactly once, inside the transaction.
            //
            // BR-017: new_wac = (old_qty * old_wac + Σ(line.qty * line.cost))
            //                   / (old_qty + Σ(line.qty))
            // ----------------------------------------------------------------

            // Group lines by item_id for WAC aggregation
            $linesByItem = $receipt->lines->groupBy('item_id');

            // Snapshot old WAC and locked old total-qty for each unique item
            // BEFORE any balance mutations occur.
            $itemSnapshots = [];
            foreach ($linesByItem as $itemId => $lines) {
                $item    = $this->itemRepository->find($itemId);
                $oldWac  = (float) $item->average_cost;

                // C-03 fix: lock all balance rows for this item so the SUM is
                // consistent within our transaction (no concurrent write can
                // change the total between this read and our own updates).
                $oldQty = (float) $this->balanceRepository->totalQuantityForItemLocked($itemId);

                $itemSnapshots[$itemId] = [
                    'item'   => $item,
                    'oldWac' => $oldWac,
                    'oldQty' => $oldQty,
                ];
            }

            // Post each individual line's stock movement
            foreach ($receipt->lines as $line) {
                $this->stockMovementService->move(
                    $receipt->property_id,
                    $line->item_id,
                    $line->location_id,
                    (string) $line->quantity,
                    TransactionTypeEnum::PurchaseReceipt,
                    (string) $line->unit_cost,
                    $receipt->id,
                    $receipt->receipt_number,
                    $userId
                );
            }

            // Now compute and save one WAC update per unique item
            foreach ($linesByItem as $itemId => $lines) {
                ['item' => $item, 'oldWac' => $oldWac, 'oldQty' => $oldQty] = $itemSnapshots[$itemId];

                // Aggregate this receipt's contribution for this item
                $receiptQty  = $lines->sum(fn ($l) => (float) $l->quantity);
                $receiptValue = $lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_cost);

                $newTotalQty = $oldQty + $receiptQty;

                $newWac = 0.0;
                if ($newTotalQty > 0) {
                    // Standard WAC formula
                    $newWac = (($oldQty * $oldWac) + $receiptValue) / $newTotalQty;
                }

                $this->itemRepository->update($item->id, ['average_cost' => $newWac]);
            }

            $this->receiptRepository->update($receipt->id, [
                'status'    => ReceiptStatusEnum::Posted->value,
                'posted_at' => now(),
                'posted_by' => $userId ?? auth()->id(),
            ]);
        });

        return $this->receiptRepository->find($id);
    }
}

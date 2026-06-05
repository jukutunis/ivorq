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

        DB::transaction(function () use ($receipt, $userId) {
            foreach ($receipt->lines as $line) {
                $item = $this->itemRepository->find($line->item_id);
                $oldQty = (float) $this->balanceRepository->totalQuantityForItem($item->id);
                $oldWac = (float) $item->average_cost;

                // Move stock
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

                // Update WAC
                $receiptQty = (float) $line->quantity;
                $receiptCost = (float) $line->unit_cost;
                $newTotalQty = $oldQty + $receiptQty;

                $newWac = 0;
                if ($newTotalQty > 0) {
                    $newWac = (($oldQty * $oldWac) + ($receiptQty * $receiptCost)) / $newTotalQty;
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

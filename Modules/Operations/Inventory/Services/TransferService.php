<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransferRepository;

class TransferService
{
    public function __construct(
        private InventoryTransferRepository $transferRepository,
        private StockMovementService $stockMovementService,
        private InventoryItemRepository $itemRepository
    ) {}

    public function create(array $data): InventoryTransfer
    {
        $data['status'] = TransferStatusEnum::Draft->value; // Or Submitted if there's no Draft
        return $this->transferRepository->create($data);
    }

    public function complete(string $id, ?string $userId = null): InventoryTransfer
    {
        $transfer = $this->transferRepository->find($id);

        // According to rules: submitted -> completed only. Assuming status has canTransitionTo
        if (! $transfer->status->canTransitionTo(TransferStatusEnum::Completed)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition transfer from {$transfer->status->label()} to Completed."],
            ]);
        }

        DB::transaction(function () use ($transfer, $userId) {
            foreach ($transfer->lines as $line) {
                $item = $this->itemRepository->find($line->item_id);
                $unitCost = (string) $item->average_cost;
                $quantity = (float) $line->quantity_requested;

                // Stock out from source
                $this->stockMovementService->move(
                    $transfer->property_id,
                    $line->item_id,
                    $transfer->from_location_id,
                    (string) (-1 * $quantity),
                    TransactionTypeEnum::TransferOut,
                    $unitCost,
                    $transfer->id,
                    $transfer->transfer_number,
                    $userId
                );

                // Stock in to destination
                $this->stockMovementService->move(
                    $transfer->property_id,
                    $line->item_id,
                    $transfer->to_location_id,
                    (string) $quantity,
                    TransactionTypeEnum::TransferIn,
                    $unitCost,
                    $transfer->id,
                    $transfer->transfer_number,
                    $userId
                );
            }

            $this->transferRepository->update($transfer->id, [
                'status'       => TransferStatusEnum::Completed->value,
                'completed_at' => now(),
                'completed_by' => $userId ?? auth()->id(),
            ]);
        });

        return $this->transferRepository->find($id);
    }
}

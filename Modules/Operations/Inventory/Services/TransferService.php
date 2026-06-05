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
        $data['status'] = TransferStatusEnum::Draft->value;
        return $this->transferRepository->create($data);
    }

    public function complete(string $id, ?string $userId = null): InventoryTransfer
    {
        $transfer = $this->transferRepository->find($id);

        if (! $transfer->status->canTransitionTo(TransferStatusEnum::Completed)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition transfer from {$transfer->status->label()} to Completed."],
            ]);
        }

        // BR-051: at least one line required before completing
        if ($transfer->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['A transfer must have at least one line before it can be completed.'],
            ]);
        }

        DB::transaction(function () use ($transfer, $userId) {
            foreach ($transfer->lines as $line) {
                $quantity = (float) $line->quantity_requested;

                // V1: transfer movements carry null unit_cost / null total_value (BR-019, BR-055).
                // Adjustment lines always use the header location_id (by design — no per-line location).

                // Stock out from source location
                $this->stockMovementService->move(
                    $transfer->property_id,
                    $line->item_id,
                    $transfer->from_location_id,
                    (string) (-1 * $quantity),
                    TransactionTypeEnum::TransferOut,
                    null,  // BR-019: no cost tracking on transfers in V1
                    $transfer->id,
                    $transfer->transfer_number,
                    $userId
                );

                // Stock in to destination location
                $this->stockMovementService->move(
                    $transfer->property_id,
                    $line->item_id,
                    $transfer->to_location_id,
                    (string) $quantity,
                    TransactionTypeEnum::TransferIn,
                    null,  // BR-019: no cost tracking on transfers in V1
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

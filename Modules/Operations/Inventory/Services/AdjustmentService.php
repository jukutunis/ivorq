<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Repositories\InventoryAdjustmentRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;

class AdjustmentService
{
    public function __construct(
        private InventoryAdjustmentRepository $adjustmentRepository,
        private StockMovementService $stockMovementService,
        private InventoryItemRepository $itemRepository,
        private InventoryStockBalanceRepository $balanceRepository
    ) {}

    public function create(array $data): InventoryAdjustment
    {
        $data['status'] = AdjustmentStatusEnum::Draft->value;
        return $this->adjustmentRepository->create($data);
    }

    public function submit(string $id): InventoryAdjustment
    {
        $adjustment = $this->adjustmentRepository->find($id);

        if (! $adjustment->status->canTransitionTo(AdjustmentStatusEnum::Submitted)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition adjustment from {$adjustment->status->label()} to Submitted."],
            ]);
        }

        return $this->adjustmentRepository->update($id, [
            'status'       => AdjustmentStatusEnum::Submitted->value,
            'submitted_at' => now(),
            'submitted_by' => auth()->id(),
        ]);
    }

    public function approve(string $id, ?string $userId = null): InventoryAdjustment
    {
        $adjustment = $this->adjustmentRepository->find($id);

        if (! $adjustment->status->canTransitionTo(AdjustmentStatusEnum::Approved)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition adjustment from {$adjustment->status->label()} to Approved."],
            ]);
        }

        DB::transaction(function () use ($adjustment, $userId) {
            foreach ($adjustment->lines as $line) {
                $balance = $this->balanceRepository->lockForUpdate($line->item_id, $adjustment->location_id);
                $currentQty = $balance ? (float) $balance->quantity : 0.0;

                if ($currentQty !== (float) $line->quantity_system) {
                    throw ValidationException::withMessages([
                        'staleness' => ["System quantity has changed for item {$line->item_id} since adjustment was created. Expected: {$line->quantity_system}, Actual: {$currentQty}"],
                    ]);
                }

                $variance = (float) $line->quantity_variance;
                if ($variance == 0) {
                    continue;
                }

                $item = $this->itemRepository->find($line->item_id);
                
                // positive adjustment may use unit_cost from the line
                // negative adjustment uses the item's average_cost
                $unitCost = $variance > 0 && $line->unit_cost 
                    ? (string) $line->unit_cost 
                    : (string) $item->average_cost;

                $movementType = $variance > 0 
                    ? TransactionTypeEnum::AdjustmentIn 
                    : TransactionTypeEnum::AdjustmentOut;

                $this->stockMovementService->move(
                    $adjustment->property_id,
                    $line->item_id,
                    $adjustment->location_id,
                    (string) $variance,
                    $movementType,
                    $unitCost,
                    $adjustment->id,
                    $adjustment->adjustment_number,
                    $userId
                );
            }

            $this->adjustmentRepository->update($adjustment->id, [
                'status'      => AdjustmentStatusEnum::Approved->value,
                'approved_at' => now(),
                'approved_by' => $userId ?? auth()->id(),
            ]);
        });

        return $this->adjustmentRepository->find($id);
    }
}

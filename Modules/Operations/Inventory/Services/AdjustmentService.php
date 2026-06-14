<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Repositories\InventoryAdjustmentRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;

class AdjustmentService
{
    public function __construct(
        private InventoryAdjustmentRepository $adjustmentRepository,
        private StockMovementService $stockMovementService,
        private InventoryItemRepository $itemRepository,
        private InventoryStockRepository $stockRepository
    ) {}

    public function create(array $data): InventoryAdjustment
    {
        $data['status'] = AdjustmentStatusEnum::Draft->value;
        return $this->adjustmentRepository->create($data);
    }

    public function submit(string $id, ?string $userId = null): InventoryAdjustment
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
            'submitted_by' => $userId ?? auth()->id(),  // M-01: use injected userId or fallback
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

        // L-01: at least one line required before approving
        if ($adjustment->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['An adjustment must have at least one line before it can be approved.'],
            ]);
        }

        DB::transaction(function () use ($adjustment, $userId) {
            foreach ($adjustment->lines as $line) {
                // BR-065: staleness check — lock balance row for this item at the
                // adjustment header location (adjustment lines always share the
                // header location_id by design; no per-line location override).
                $balance    = $this->stockRepository->findOrCreateLocked($line->item_id, $adjustment->location_id, $adjustment->property_id);
                $currentQty = (float) $balance->physical_quantity;

                if ($currentQty !== (float) $line->quantity_system) {
                    throw ValidationException::withMessages([
                        'staleness' => ["System quantity has changed for item {$line->item_id} since adjustment was created. Expected: {$line->quantity_system}, Actual: {$currentQty}"],
                    ]);
                }

                $variance = (float) $line->quantity_variance;

                // BR-063: skip zero-variance lines — no stock card written
                if ($variance == 0) {
                    continue;
                }

                $item = $this->itemRepository->find($line->item_id);

                // BR-067: cost stamped at approval time using item.weighted_average_cost.
                // positive adjustment may use unit_cost from the line if provided;
                // negative adjustment always uses the item's weighted_average_cost.
                $unitCost = $variance > 0 && $line->unit_cost
                    ? (string) $line->unit_cost
                    : (string) $item->weighted_average_cost;

                $this->stockMovementService->adjust(
                    $adjustment->property_id,
                    $line->item_id,
                    $adjustment->location_id,
                    (string) $variance,
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

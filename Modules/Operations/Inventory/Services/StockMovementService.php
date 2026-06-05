<?php

namespace Modules\Operations\Inventory\Services;

use Exception;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryStockCard;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockCardRepository;

class StockMovementService
{
    public function __construct(
        private InventoryStockBalanceRepository $balanceRepository,
        private InventoryStockCardRepository $cardRepository,
        private InventoryItemRepository $itemRepository
    ) {}

    /**
     * Post a single stock movement.
     *
     * MUST be called inside an active DB::transaction().
     *
     * @throws ValidationException
     */
    public function move(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $quantityChange,
        TransactionTypeEnum $movementType,
        ?string $unitCost = null,
        ?string $referenceId = null,
        ?string $remarks = null,
        ?string $userId = null
    ): InventoryStockCard {
        // --- Guard: item must be active ----------------------------------------
        $item = $this->itemRepository->find($itemId);
        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'item' => ["Item {$itemId} is inactive and cannot receive stock movements."],
            ]);
        }

        // --- Atomic find-or-create + lock (C-02 fix) ---------------------------
        // findOrCreateLocked() issues an upsert (no-op on conflict) then locks
        // the guaranteed-existing row, avoiding the TOCTOU race between the old
        // findOrCreate() + lockForUpdate() two-step.
        $balance = $this->balanceRepository->findOrCreateLocked($itemId, $locationId, $propertyId);

        // --- Quantity check (BR-001) --------------------------------------------
        $quantityBefore = (float) $balance->quantity;
        $change         = (float) $quantityChange;
        $quantityAfter  = $quantityBefore + $change;

        if ($quantityAfter < 0) {
            throw ValidationException::withMessages([
                'stock' => ['Negative stock is not allowed for item ' . $itemId . ' at location ' . $locationId],
            ]);
        }

        // --- Status recomputation (BR-007, M-06 fix) ---------------------------
        // Three-way: out_of_stock / low_stock / in_stock
        // reorder_point is NOT NULL (default 0) — when 0, low_stock is inactive.
        $reorderPoint = (float) $item->reorder_point;
        $newStatus = match (true) {
            $quantityAfter <= 0                                  => ItemStatusEnum::OutOfStock,
            $reorderPoint > 0 && $quantityAfter < $reorderPoint => ItemStatusEnum::LowStock,
            default                                              => ItemStatusEnum::InStock,
        };

        // --- Update balance -----------------------------------------------------
        $this->balanceRepository->updateBalance(
            $balance->id,
            (string) $quantityAfter,
            $newStatus,
            now()
        );

        // --- Calculate total_value ---------------------------------------------
        // unit_cost may be null for transfer movements (BR-019)
        $totalValue = ($unitCost !== null) ? $change * (float) $unitCost : null;

        // --- Write stock card (append-only ledger, BR-005) ----------------------
        return $this->cardRepository->create([
            'property_id'     => $propertyId,
            'item_id'         => $itemId,
            'location_id'     => $locationId,
            'movement_type'   => $movementType->value,
            'quantity_before' => $quantityBefore,
            'quantity_change' => $change,
            'quantity_after'  => $quantityAfter,
            'unit_cost'       => $unitCost,
            'total_value'     => $totalValue,
            'reference_id'    => $referenceId,
            'remarks'         => $remarks,
            'posted_at'       => now(),
            'posted_by'       => $userId ?? auth()->id(),
        ]);
    }
}

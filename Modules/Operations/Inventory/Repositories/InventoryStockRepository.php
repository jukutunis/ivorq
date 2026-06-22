<?php

namespace Modules\Operations\Inventory\Repositories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Models\InventoryStock;

class InventoryStockRepository
{
    public function forItem(string $itemId): Collection
    {
        return InventoryStock::where('item_id', $itemId)
            ->with('location')
            ->get();
    }

    public function forLocation(string $locationId): Collection
    {
        return InventoryStock::where('location_id', $locationId)
            ->with(['item.category', 'item.unit'])
            ->get();
    }

    public function findOrCreateLocked(string $itemId, string $locationId, string $propertyId): InventoryStock
    {
        // Ensure the row exists (no-op if already present)
        InventoryStock::firstOrCreate(
                ['item_id' => $itemId, 'location_id' => $locationId],
                [
                    'property_id'       => $propertyId,
                    'physical_quantity' => 0,
                    'reserved_quantity' => 0,
                    'status'            => ItemStatusEnum::OutOfStock->value,
                ]
            );

        // Acquire the exclusive lock on the now-guaranteed-existing row
        return InventoryStock::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @deprecated Use findOrCreateLocked() inside a transaction instead.
     */
    public function findOrCreate(string $itemId, string $locationId, string $propertyId): InventoryStock
    {
        return InventoryStock::firstOrCreate(
            ['item_id' => $itemId, 'location_id' => $locationId],
            [
                'property_id'       => $propertyId,
                'physical_quantity' => 0,
                'reserved_quantity' => 0,
                'status'            => ItemStatusEnum::OutOfStock->value,
            ]
        );
    }

    public function createOrLockControlled(string $propertyId, string $itemId, string $locationId): InventoryStock
    {
        InventoryStock::insertOrIgnore([
            'id'                => (string) \Illuminate\Support\Str::ulid(),
            'property_id'       => $propertyId,
            'item_id'           => $itemId,
            'location_id'       => $locationId,
            'physical_quantity' => 0,
            'reserved_quantity' => 0,
            'status'            => ItemStatusEnum::OutOfStock->value,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return InventoryStock::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function updateBalance(
        string            $id,
        string            $physicalQuantity,
        ItemStatusEnum    $status,
        ?DateTimeInterface $lastMovementAt = null
    ): void {
        InventoryStock::where('id', $id)->update([
            'physical_quantity' => $physicalQuantity,
            'status'            => $status->value,
            'last_movement_at'  => $lastMovementAt ?? now(),
        ]);
    }

    /**
     * Plain (unlocked) SUM — use only outside transactions or where
     * a stale read is acceptable (e.g. reporting).
     */
    public function totalQuantityForItem(string $itemId): string
    {
        return (string) InventoryStock::where('item_id', $itemId)->sum('physical_quantity');
    }

    /**
     * Locked SUM — use inside a DB::transaction() when the result must be
     * consistent with concurrent writes (e.g. WAC calculation in ReceiptService).
     */
    public function totalQuantityForItemLocked(string $itemId): string
    {
        return (string) InventoryStock::where('item_id', $itemId)
            ->lockForUpdate()
            ->sum('physical_quantity');
    }

    public function lockForUpdate(string $itemId, string $locationId): ?InventoryStock
    {
        return InventoryStock::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
    }
}

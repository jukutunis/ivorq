<?php

namespace Modules\Operations\Inventory\Repositories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Models\InventoryStockBalance;

class InventoryStockBalanceRepository
{
    public function forItem(string $itemId): Collection
    {
        return InventoryStockBalance::where('item_id', $itemId)
            ->with('location')
            ->get();
    }

    public function forLocation(string $locationId): Collection
    {
        return InventoryStockBalance::where('location_id', $locationId)
            ->with(['item.category', 'item.unit'])
            ->get();
    }

    public function findOrCreate(string $itemId, string $locationId, string $propertyId): InventoryStockBalance
    {
        return InventoryStockBalance::firstOrCreate(
            ['item_id' => $itemId, 'location_id' => $locationId],
            [
                'property_id' => $propertyId,
                'quantity'    => 0,
                'status'      => ItemStatusEnum::OutOfStock->value,
            ]
        );
    }

    public function updateBalance(
        string            $id,
        string            $quantity,
        ItemStatusEnum    $status,
        ?DateTimeInterface $lastMovementAt = null
    ): void {
        InventoryStockBalance::where('id', $id)->update([
            'quantity'         => $quantity,
            'status'           => $status->value,
            'last_movement_at' => $lastMovementAt ?? now(),
        ]);
    }

    public function totalQuantityForItem(string $itemId): string
    {
        return (string) InventoryStockBalance::where('item_id', $itemId)->sum('quantity');
    }

    public function lockForUpdate(string $itemId, string $locationId): ?InventoryStockBalance
    {
        return InventoryStockBalance::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
    }
}

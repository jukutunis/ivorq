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

    /**
     * Atomically find or create the balance row for the given item+location,
     * then immediately acquire a pessimistic write-lock on it.
     *
     * MUST be called inside an active DB::transaction().
     *
     * We bypass the property global scope so this method is property-agnostic
     * and safe to call when the scope may not be set (e.g. background jobs).
     * The caller supplies $propertyId explicitly.
     *
     * Race safety:
     *  - SQLite (tests): single-writer; no real concurrency.
     *  - PostgreSQL (production): if two concurrent transactions both reach the
     *    firstOrCreate() and one inserts, the other hits the unique constraint
     *    and its transaction is rolled back — the caller retries naturally.
     *    The subsequent lockForUpdate ensures we hold an exclusive row lock
     *    for the remainder of the transaction.
     */
    public function findOrCreateLocked(string $itemId, string $locationId, string $propertyId): InventoryStockBalance
    {
        // Ensure the row exists (no-op if already present)
        InventoryStockBalance::withoutGlobalScope('property')
            ->firstOrCreate(
                ['item_id' => $itemId, 'location_id' => $locationId],
                [
                    'property_id' => $propertyId,
                    'quantity'    => 0,
                    'status'      => ItemStatusEnum::OutOfStock->value,
                ]
            );

        // Acquire the exclusive lock on the now-guaranteed-existing row
        return InventoryStockBalance::withoutGlobalScope('property')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @deprecated Use findOrCreateLocked() inside a transaction instead.
     */
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

    /**
     * Plain (unlocked) SUM — use only outside transactions or where
     * a stale read is acceptable (e.g. reporting).
     */
    public function totalQuantityForItem(string $itemId): string
    {
        return (string) InventoryStockBalance::where('item_id', $itemId)->sum('quantity');
    }

    /**
     * Locked SUM — use inside a DB::transaction() when the result must be
     * consistent with concurrent writes (e.g. WAC calculation in ReceiptService).
     *
     * Acquires a shared / exclusive row-lock on every balance row for the item
     * so that concurrent receipts see a consistent total.
     */
    public function totalQuantityForItemLocked(string $itemId): string
    {
        return (string) InventoryStockBalance::withoutGlobalScope('property')
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->sum('quantity');
    }

    public function lockForUpdate(string $itemId, string $locationId): ?InventoryStockBalance
    {
        return InventoryStockBalance::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
    }
}

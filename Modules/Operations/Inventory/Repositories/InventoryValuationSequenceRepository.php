<?php

namespace Modules\Operations\Inventory\Repositories;

use Modules\Operations\Inventory\Models\InventoryValuationSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryValuationSequenceRepository
{
    public function allocateNext(string $propertyId, string $locationId, string $itemId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \RuntimeException(
                'Sequence counter allocation requires an active transaction.'
            );
        }

        // 1. PostgreSQL-safe insert-if-absent (ON CONFLICT DO NOTHING)
        DB::table('inventory_valuation_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'location_id' => $locationId,
            'item_id' => $itemId,
            'last_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Reload and lock the counter row in the current transaction
        $counter = InventoryValuationSequence::where('property_id', $propertyId)
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            throw new \RuntimeException('Failed to resolve sequence counter row.');
        }

        // 3. Increment and save
        $counter->last_sequence += 1;
        $counter->save();

        return (int) $counter->last_sequence;
    }
}

<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStockBalance;

class InventoryOpeningBalanceSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $admin = User::whereHas('roles', fn($q) => $q->where('name', 'super-admin'))
            ->orWhereHas('roles', fn($q) => $q->where('name', 'property-admin'))
            ->first();

        // Map location codes → IDs
        $locations = InventoryLocation::where('property_id', $property->id)
            ->pluck('id', 'location_code');

        // Map item codes → items
        $items = InventoryItem::where('property_id', $property->id)
            ->get()
            ->keyBy('item_code');

        if ($locations->isEmpty() || $items->isEmpty()) {
            $this->command->warn('InventoryOpeningBalanceSeeder: locations or items missing — run location/item seeders first.');
            return;
        }

        // Opening stock: [item_code, location_code, quantity, average_cost]
        $openingStock = [
            // Main Storeroom bulk stock
            ['HK-SOAP-001',    'MAIN-STR',   2000, '1200.0000'],
            ['HK-SHAMP-001',   'MAIN-STR',   1500, '3500.0000'],
            ['HK-COND-001',    'MAIN-STR',   1500, '3500.0000'],
            ['HK-TOWEL-001',   'MAIN-STR',    200, '85000.0000'],
            ['HK-LINEN-001',   'MAIN-STR',    160, '120000.0000'],
            ['HK-TISSUE-001',  'MAIN-STR',    500, '8500.0000'],
            ['ENG-BULB-LED01', 'MAIN-STR',     50, '25000.0000'],
            ['ENG-FILT-AC01',  'MAIN-STR',     20, '45000.0000'],
            ['ENG-TAPE-PTFE',  'MAIN-STR',     60, '5000.0000'],
            ['ENG-BATT-AA',    'MAIN-STR',     30, '22000.0000'],
            ['LDY-DET-001',    'MAIN-STR',     10, '185000.0000'],
            ['LDY-SOFT-001',   'MAIN-STR',      6, '220000.0000'],
            ['LDY-STARCH-001', 'MAIN-STR',     12, '35000.0000'],
            ['MB-WATER-001',   'MAIN-STR',    500, '8000.0000'],
            ['MB-COLA-001',    'MAIN-STR',    300, '12000.0000'],
            ['MB-PNUT-001',    'MAIN-STR',    300, '15000.0000'],
            ['OFF-PAPER-A4',   'MAIN-STR',     50, '55000.0000'],
            ['OFF-PEN-001',    'MAIN-STR',     20, '35000.0000'],
            ['OFF-TONER-001',  'MAIN-STR',      4, '350000.0000'],

            // Housekeeping sub-store (working stock)
            ['HK-SOAP-001',    'HK-STORE',    400, '1200.0000'],
            ['HK-SHAMP-001',   'HK-STORE',    300, '3500.0000'],
            ['HK-COND-001',    'HK-STORE',    300, '3500.0000'],
            ['HK-TOWEL-001',   'HK-STORE',     60, '85000.0000'],
            ['HK-LINEN-001',   'HK-STORE',     40, '120000.0000'],
            ['HK-TISSUE-001',  'HK-STORE',    100, '8500.0000'],

            // Engineering sub-store
            ['ENG-BULB-LED01', 'ENG-STORE',   15, '25000.0000'],
            ['ENG-FILT-AC01',  'ENG-STORE',    8, '45000.0000'],
            ['ENG-TAPE-PTFE',  'ENG-STORE',   20, '5000.0000'],
            ['ENG-BATT-AA',    'ENG-STORE',   10, '22000.0000'],

            // Laundry sub-store
            ['LDY-DET-001',    'LAUNDRY-STR',  3, '185000.0000'],
            ['LDY-SOFT-001',   'LAUNDRY-STR',  2, '220000.0000'],
            ['LDY-STARCH-001', 'LAUNDRY-STR',  4, '35000.0000'],

            // Minibar replenishment store
            ['MB-WATER-001',   'MINIBAR-STR', 200, '8000.0000'],
            ['MB-COLA-001',    'MINIBAR-STR', 100, '12000.0000'],
            ['MB-PNUT-001',    'MINIBAR-STR', 120, '15000.0000'],
        ];

        foreach ($openingStock as [$itemCode, $locationCode, $qty, $cost]) {
            $item     = $items[$itemCode] ?? null;
            $locId    = $locations[$locationCode] ?? null;

            if (! $item || ! $locId) {
                continue;
            }

            // Idempotent: only create if the balance row doesn't exist yet
            $balance = InventoryStockBalance::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'item_id'     => $item->id,
                    'location_id' => $locId,
                ],
                [
                    'property_id' => $property->id,
                    'item_id'     => $item->id,
                    'location_id' => $locId,
                    'quantity'    => $qty,
                ]
            );

            // If just created, also update the item's average_cost if not set
            if ($balance->wasRecentlyCreated) {
                $item->updateQuietly(['average_cost' => $cost]);
            }
        }
    }
}

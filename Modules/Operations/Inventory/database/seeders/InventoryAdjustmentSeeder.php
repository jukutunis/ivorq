<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStockBalance;

class InventoryAdjustmentSeeder extends Seeder
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

        $items     = InventoryItem::where('property_id', $property->id)->pluck('id', 'sku');
        $locations = InventoryLocation::where('property_id', $property->id)->pluck('id', 'name');

        $mainStore = $locations['MAIN-STR'] ?? null;
        $hkStore   = $locations['HK-STORE'] ?? null;

        if (! $mainStore || $items->isEmpty()) {
            $this->command->warn('InventoryAdjustmentSeeder: prerequisites missing — run location/item seeders first.');
            return;
        }

        // Helper: get system quantity for a given item at a location
        $sysQty = fn($itemCode, $locId) =>
            ($itemId = $items[$itemCode] ?? null)
                ? (float) InventoryStockBalance::where('item_id', $itemId)
                      ->where('location_id', $locId)
                      ->value('quantity') ?? 0
                : 0.0;

        $adjustments = [
            [
                'adjustment_number' => 'ADJ-2024-001',
                'location_id'       => $mainStore,
                'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
                'status'            => AdjustmentStatusEnum::Approved->value,
                'reason'            => 'Quarterly stock count — Main Storeroom (Q3 2024).',
                'submitted_by'      => $admin?->id,
                'submitted_at'      => now()->subDays(15),
                'approved_by'       => $admin?->id,
                'approved_at'       => now()->subDays(14),
                'lines'             => [
                    // Minor variance found during physical count
                    ['sku' => 'HK-SOAP-001',    'sys' => 2000, 'actual' => 1985, 'var' => -15],
                    ['sku' => 'HK-SHAMP-001',   'sys' => 1500, 'actual' => 1492, 'var' =>  -8],
                    ['sku' => 'ENG-BULB-LED01', 'sys' =>   50, 'actual' =>   48, 'var' =>  -2],
                ],
            ],
            [
                'adjustment_number' => 'ADJ-2024-002',
                'location_id'       => $hkStore,
                'adjustment_type'   => AdjustmentTypeEnum::Damaged->value,
                'status'            => AdjustmentStatusEnum::Submitted->value,
                'reason'            => 'Damaged towels found during room inspection — 3 bath towels irreparable.',
                'submitted_by'      => $admin?->id,
                'submitted_at'      => now()->subDays(5),
                'approved_by'       => null,
                'approved_at'       => null,
                'lines'             => [
                    ['sku' => 'HK-TOWEL-001', 'sys' => 60, 'actual' => 57, 'var' => -3],
                ],
            ],
            [
                'adjustment_number' => 'ADJ-2024-003',
                'location_id'       => $mainStore,
                'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
                'status'            => AdjustmentStatusEnum::Draft->value,
                'reason'            => 'Linen count — end of month check.',
                'submitted_by'      => null,
                'submitted_at'      => null,
                'approved_by'       => null,
                'approved_at'       => null,
                'lines'             => [
                    ['sku' => 'HK-LINEN-001', 'sys' => 160, 'actual' => 158, 'var' => -2],
                ],
            ],
        ];

        foreach ($adjustments as $adjData) {
            $lines = $adjData['lines'];
            unset($adjData['lines']);

            $adjustment = InventoryAdjustment::firstOrCreate(
                [
                    'property_id'       => $property->id,
                    'adjustment_number' => $adjData['adjustment_number'],
                ],
                array_merge($adjData, ['property_id' => $property->id])
            );

            if ($adjustment->wasRecentlyCreated) {
                foreach ($lines as $line) {
                    $itemId = $items[$line['sku']] ?? null;
                    if (! $itemId) {
                        continue;
                    }

                    InventoryAdjustmentLine::create([
                        'property_id'       => $property->id,
                        'adjustment_id'     => $adjustment->id,
                        'item_id'           => $itemId,
                        'quantity_system'   => $line['sys'],
                        'quantity_actual'   => $line['actual'],
                        'quantity_variance' => $line['var'],
                    ]);
                }
            }
        }
    }
}

<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;

class InventoryTransferSeeder extends Seeder
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

        $mainStore  = $locations['MAIN-STR']     ?? null;
        $hkStore    = $locations['HK-STORE']     ?? null;
        $engStore   = $locations['ENG-STORE']    ?? null;
        $minibarStr = $locations['MINIBAR-STR']  ?? null;

        if (! $mainStore || $items->isEmpty()) {
            $this->command->warn('InventoryTransferSeeder: prerequisites missing — run location/item seeders first.');
            return;
        }

        $transfers = [
            [
                'transfer_number'  => 'TRF-2024-001',
                'from_location_id' => $mainStore,
                'to_location_id'   => $hkStore,
                'status'           => TransferStatusEnum::Completed->value,
                'requested_by'     => $admin?->id,
                'approved_by'      => $admin?->id,
                'approved_at'      => now()->subDays(22),
                'completed_by'     => $admin?->id,
                'completed_at'     => now()->subDays(22),
                'notes'            => 'Weekly housekeeping sub-store replenishment.',
                'lines'            => [
                    ['sku' => 'HK-SOAP-001',   'qty' => 400],
                    ['sku' => 'HK-SHAMP-001',  'qty' => 300],
                    ['sku' => 'HK-COND-001',   'qty' => 300],
                    ['sku' => 'HK-TOWEL-001',  'qty' =>  60],
                    ['sku' => 'HK-LINEN-001',  'qty' =>  40],
                    ['sku' => 'HK-TISSUE-001', 'qty' => 100],
                ],
            ],
            [
                'transfer_number'  => 'TRF-2024-002',
                'from_location_id' => $mainStore,
                'to_location_id'   => $engStore,
                'status'           => TransferStatusEnum::Completed->value,
                'requested_by'     => $admin?->id,
                'approved_by'      => $admin?->id,
                'approved_at'      => now()->subDays(18),
                'completed_by'     => $admin?->id,
                'completed_at'     => now()->subDays(18),
                'notes'            => 'Engineering workshop restocking.',
                'lines'            => [
                    ['sku' => 'ENG-BULB-LED01', 'qty' => 15],
                    ['sku' => 'ENG-FILT-AC01',  'qty' =>  8],
                    ['sku' => 'ENG-TAPE-PTFE',  'qty' => 20],
                    ['sku' => 'ENG-BATT-AA',    'qty' => 10],
                ],
            ],
            [
                'transfer_number'  => 'TRF-2024-003',
                'from_location_id' => $mainStore,
                'to_location_id'   => $minibarStr,
                'status'           => TransferStatusEnum::Draft->value,
                'requested_by'     => $admin?->id,
                'approved_by'      => null,
                'approved_at'      => null,
                'completed_by'     => null,
                'completed_at'     => null,
                'notes'            => 'Minibar replenishment — pending approval.',
                'lines'            => [
                    ['sku' => 'MB-WATER-001', 'qty' => 200],
                    ['sku' => 'MB-COLA-001',  'qty' => 100],
                    ['sku' => 'MB-PNUT-001',  'qty' => 120],
                ],
            ],
        ];

        foreach ($transfers as $transferData) {
            $lines = $transferData['lines'];
            unset($transferData['lines']);

            $transfer = InventoryTransfer::firstOrCreate(
                [
                    'property_id'     => $property->id,
                    'transfer_number' => $transferData['transfer_number'],
                ],
                array_merge($transferData, ['property_id' => $property->id])
            );

            if ($transfer->wasRecentlyCreated) {
                foreach ($lines as $line) {
                    $itemId = $items[$line['sku']] ?? null;
                    if (! $itemId) {
                        continue;
                    }

                    InventoryTransferLine::create([
                        'property_id'        => $property->id,
                        'transfer_id'        => $transfer->id,
                        'item_id'            => $itemId,
                        'quantity_requested' => $line['qty'],
                    ]);
                }
            }
        }
    }
}

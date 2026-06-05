<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;

class InventoryReceiptSeeder extends Seeder
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

        $items     = InventoryItem::where('property_id', $property->id)->pluck('id', 'item_code');
        $locations = InventoryLocation::where('property_id', $property->id)->pluck('id', 'location_code');

        $mainStore = $locations['MAIN-STR'] ?? null;

        if (! $mainStore || $items->isEmpty()) {
            $this->command->warn('InventoryReceiptSeeder: prerequisites missing — run location/item seeders first.');
            return;
        }

        $receipts = [
            [
                'receipt_number'  => 'RCV-2024-001',
                'supplier_name'   => 'PT Artha Amenity Indonesia',
                'external_reference' => 'PO-2024-0045',
                'status'          => ReceiptStatusEnum::Posted->value,
                'received_at'     => now()->subDays(30),
                'posted_by'       => $admin?->id,
                'posted_at'       => now()->subDays(30),
                'remarks'         => 'Monthly housekeeping amenities replenishment.',
                'lines'           => [
                    ['item_code' => 'HK-SOAP-001',   'qty' => 2000, 'unit_cost' => 1200, 'total_value' => 2400000],
                    ['item_code' => 'HK-SHAMP-001',  'qty' => 1500, 'unit_cost' => 3500, 'total_value' => 5250000],
                    ['item_code' => 'HK-COND-001',   'qty' => 1500, 'unit_cost' => 3500, 'total_value' => 5250000],
                    ['item_code' => 'HK-TISSUE-001', 'qty' =>  500, 'unit_cost' => 8500, 'total_value' => 4250000],
                ],
            ],
            [
                'receipt_number'  => 'RCV-2024-002',
                'supplier_name'   => 'CV Mitra Teknik Mandiri',
                'external_reference' => 'PO-2024-0052',
                'status'          => ReceiptStatusEnum::Posted->value,
                'received_at'     => now()->subDays(20),
                'posted_by'       => $admin?->id,
                'posted_at'       => now()->subDays(20),
                'remarks'         => 'Engineering spares restock.',
                'lines'           => [
                    ['item_code' => 'ENG-BULB-LED01', 'qty' => 50, 'unit_cost' => 25000,  'total_value' => 1250000],
                    ['item_code' => 'ENG-FILT-AC01',  'qty' => 20, 'unit_cost' => 45000,  'total_value' =>  900000],
                    ['item_code' => 'ENG-TAPE-PTFE',  'qty' => 60, 'unit_cost' =>  5000,  'total_value' =>  300000],
                    ['item_code' => 'ENG-BATT-AA',    'qty' => 30, 'unit_cost' => 22000,  'total_value' =>  660000],
                ],
            ],
            [
                'receipt_number'  => 'RCV-2024-003',
                'supplier_name'   => 'PT Laundry Chemical Nusantara',
                'external_reference' => 'PO-2024-0060',
                'status'          => ReceiptStatusEnum::Draft->value,
                'received_at'     => null,
                'posted_by'       => null,
                'posted_at'       => null,
                'remarks'         => 'Laundry chemicals Q4 order — pending receipt confirmation.',
                'lines'           => [
                    ['item_code' => 'LDY-DET-001',    'qty' => 10, 'unit_cost' => 185000, 'total_value' => 1850000],
                    ['item_code' => 'LDY-SOFT-001',   'qty' =>  6, 'unit_cost' => 220000, 'total_value' => 1320000],
                    ['item_code' => 'LDY-STARCH-001', 'qty' => 12, 'unit_cost' =>  35000, 'total_value' =>  420000],
                ],
            ],
        ];

        foreach ($receipts as $receiptData) {
            $lines = $receiptData['lines'];
            unset($receiptData['lines']);

            $receipt = InventoryReceipt::firstOrCreate(
                [
                    'property_id'    => $property->id,
                    'receipt_number' => $receiptData['receipt_number'],
                ],
                array_merge($receiptData, ['property_id' => $property->id])
            );

            if ($receipt->wasRecentlyCreated) {
                foreach ($lines as $line) {
                    $itemId = $items[$line['item_code']] ?? null;
                    if (! $itemId) {
                        continue;
                    }

                    InventoryReceiptLine::create([
                        'property_id' => $property->id,
                        'receipt_id'  => $receipt->id,
                        'item_id'     => $itemId,
                        'location_id' => $mainStore,
                        'quantity'    => $line['qty'],
                        'unit_cost'   => $line['unit_cost'],
                        'total_value' => $line['total_value'],
                    ]);
                }
            }
        }
    }
}

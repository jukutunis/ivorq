<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Models\InventoryLocation;

class InventoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $locations = [
            [
                'location_code' => 'MAIN-STR',
                'name'          => 'Main Storeroom',
                'location_type' => LocationTypeEnum::MainStore->value,
                'description'   => 'Primary central inventory store — all receiving and central stock.',
                'is_active'     => true,
            ],
            [
                'location_code' => 'HK-STORE',
                'name'          => 'Housekeeping Store',
                'location_type' => LocationTypeEnum::DepartmentStore->value,
                'description'   => 'Housekeeping linen, amenities and cleaning supplies.',
                'is_active'     => true,
            ],
            [
                'location_code' => 'ENG-STORE',
                'name'          => 'Engineering Workshop',
                'location_type' => LocationTypeEnum::DepartmentStore->value,
                'description'   => 'Engineering spare parts and maintenance materials.',
                'is_active'     => true,
            ],
            [
                'location_code' => 'LAUNDRY-STR',
                'name'          => 'Laundry Room Store',
                'location_type' => LocationTypeEnum::LaundryStore->value,
                'description'   => 'Laundry chemicals and sundry supplies.',
                'is_active'     => true,
            ],
            [
                'location_code' => 'FB-STR',
                'name'          => 'F&B Dry Store',
                'location_type' => LocationTypeEnum::DepartmentStore->value,
                'description'   => 'Non-perishable F&B consumables and disposables.',
                'is_active'     => true,
            ],
            [
                'location_code' => 'MINIBAR-STR',
                'name'          => 'Minibar Replenishment Store',
                'location_type' => LocationTypeEnum::MinibarStore->value,
                'description'   => 'Minibar stock holding area for daily floor replenishment.',
                'is_active'     => true,
            ],
        ];

        foreach ($locations as $data) {
            InventoryLocation::firstOrCreate(
                [
                    'property_id'   => $property->id,
                    'location_code' => $data['location_code'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

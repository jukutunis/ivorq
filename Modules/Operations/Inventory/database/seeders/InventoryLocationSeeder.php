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
                                'name'          => 'Main Storeroom',
                'type' => LocationTypeEnum::MainStore->value,
                                            ],
            [
                                'name'          => 'Housekeeping Store',
                'type' => LocationTypeEnum::DepartmentStore->value,
                                            ],
            [
                                'name'          => 'Engineering Workshop',
                'type' => LocationTypeEnum::DepartmentStore->value,
                                            ],
            [
                                'name'          => 'Laundry Room Store',
                'type' => LocationTypeEnum::LaundryStore->value,
                                            ],
            [
                                'name'          => 'F&B Dry Store',
                'type' => LocationTypeEnum::DepartmentStore->value,
                                            ],
            [
                                'name'          => 'Minibar Replenishment Store',
                'type' => LocationTypeEnum::MinibarStore->value,
                                            ],
        ];

        foreach ($locations as $data) {
            InventoryLocation::firstOrCreate(
                [
                    'property_id'   => $property->id,
                    'name' => $data['name'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

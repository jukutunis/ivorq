<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;

class InventoryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $categories = [
            [
                'name'          => 'Housekeeping Amenities',
                'description'   => 'Guest room toiletries, linen supplies and cleaning consumables.',
            ],
            [
                'name'          => 'Engineering Spare Parts',
                'description'   => 'Mechanical, electrical and plumbing replacement parts.',
            ],
            [
                'name'          => 'Laundry Supplies',
                'description'   => 'Detergents, softeners, starch and laundry chemicals.',
            ],
            [
                'name'          => 'Minibar Items',
                'description'   => 'Packaged beverages, snacks and minibar sundries.',
            ],
            [
                'name'          => 'Office Supplies',
                'description'   => 'Stationery, printing consumables and general office materials.',
            ],
            [
                'name'          => 'Food & Beverage Consumables',
                'description'   => 'Disposables, packaging, and non-perishable F&B supplies.',
            ],
        ];

        foreach ($categories as $data) {
            InventoryCategory::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'name'        => $data['name'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

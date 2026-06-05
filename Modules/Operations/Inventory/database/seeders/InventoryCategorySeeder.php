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
                'category_code' => 'HK-AMEN',
                'name'          => 'Housekeeping Amenities',
                'description'   => 'Guest room toiletries, linen supplies and cleaning consumables.',
                'is_active'     => true,
            ],
            [
                'category_code' => 'ENG-PARTS',
                'name'          => 'Engineering Spare Parts',
                'description'   => 'Mechanical, electrical and plumbing replacement parts.',
                'is_active'     => true,
            ],
            [
                'category_code' => 'LAUNDRY',
                'name'          => 'Laundry Supplies',
                'description'   => 'Detergents, softeners, starch and laundry chemicals.',
                'is_active'     => true,
            ],
            [
                'category_code' => 'MINIBAR',
                'name'          => 'Minibar Items',
                'description'   => 'Packaged beverages, snacks and minibar sundries.',
                'is_active'     => true,
            ],
            [
                'category_code' => 'OFFICE',
                'name'          => 'Office Supplies',
                'description'   => 'Stationery, printing consumables and general office materials.',
                'is_active'     => true,
            ],
            [
                'category_code' => 'FB-CONS',
                'name'          => 'Food & Beverage Consumables',
                'description'   => 'Disposables, packaging, and non-perishable F&B supplies.',
                'is_active'     => true,
            ],
        ];

        foreach ($categories as $data) {
            InventoryCategory::firstOrCreate(
                [
                    'property_id'   => $property->id,
                    'category_code' => $data['category_code'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

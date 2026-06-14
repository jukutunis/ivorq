<?php

namespace Modules\Operations\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;

class VendorCategorySeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();
        if (!$property) return;

        $categories = [
            ['name' => 'Food & Beverage', 'category_code' => 'FNB'],
            ['name' => 'Engineering', 'category_code' => 'ENG'],
            ['name' => 'Housekeeping', 'category_code' => 'HK'],
        ];

        foreach ($categories as $cat) {
            VendorCategory::firstOrCreate(['category_code' => $cat['category_code'], 'property_id' => $property->id], [
                'id' => (string) str(\Illuminate\Support\Str::ulid()),
                'name' => $cat['name'],
                'is_active' => true,
            ]);
        }
    }
}

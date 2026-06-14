<?php

namespace Modules\Operations\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;

class DemoVendorSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();
        if (!$property) return;
        
        $fnbCat = VendorCategory::where('category_code', 'FNB')->where('property_id', $property->id)->first();

        if ($fnbCat) {
            // Global Vendor (skip BelongsToProperty enforcing)
            Vendor::withoutEvents(function () use ($fnbCat) {
                Vendor::firstOrCreate(['vendor_code' => 'SYSCO-01'], [
                    'id' => (string) str(\Illuminate\Support\Str::ulid()),
                    'property_id' => null,
                    'vendor_category_id' => $fnbCat->id,
                    'name' => 'Sysco Global Foods',
                    'is_active' => true,
                    'is_approved' => true,
                    'default_currency_code' => 'USD',
                ]);
            });

            // Local Vendor
            Vendor::firstOrCreate(['vendor_code' => 'LOCAL-BAKERY', 'property_id' => $property->id], [
                'id' => (string) str(\Illuminate\Support\Str::ulid()),
                'vendor_category_id' => $fnbCat->id,
                'name' => 'Local Artisan Bakery',
                'is_active' => true,
                'is_approved' => true,
                'default_currency_code' => 'IDR',
            ]);
        }
    }
}

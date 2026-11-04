<?php

namespace Modules\Operations\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;

class DemoVendorSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();
        if (! $property) {
            return;
        }

        $fnbCat = VendorCategory::where('category_code', 'FNB')->where('property_id', $property->id)->first();

        if ($fnbCat) {
            Vendor::withoutEvents(function () use ($fnbCat, $property) {
                Vendor::firstOrCreate(['company_id' => $property->company_id, 'vendor_code' => 'SYSCO-01'], [
                    'id' => (string) str(Str::ulid()),
                    'vendor_category_id' => $fnbCat->id,
                    'name' => 'Sysco Global Foods',
                    'is_active' => true,
                    'is_approved' => true,
                    'default_currency_code' => 'USD',
                ]);
            });

            Vendor::firstOrCreate(['company_id' => $property->company_id, 'vendor_code' => 'LOCAL-BAKERY'], [
                'id' => (string) str(Str::ulid()),
                'vendor_category_id' => $fnbCat->id,
                'name' => 'Local Artisan Bakery',
                'is_active' => true,
                'is_approved' => true,
                'default_currency_code' => 'IDR',
            ]);
        }
    }
}

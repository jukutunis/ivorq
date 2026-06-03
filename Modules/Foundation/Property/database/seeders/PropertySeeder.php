<?php

namespace Modules\Foundation\Property\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::create([
            'name'      => 'IVORQ Hotels Group',
            'slug'      => 'ivorq-hotels-group',
            'email'     => 'group@ivorq.com',
            'is_active' => true,
        ]);

        Property::create([
            'company_id' => $company->id,
            'name'       => 'IVORQ Grand Hotel',
            'slug'       => 'ivorq-grand-hotel',
            'code'       => 'IGH',
            'email'      => 'info@ivorqgrand.com',
            'city'       => 'Jakarta',
            'country'    => 'Indonesia',
            'timezone'   => 'Asia/Jakarta',
            'currency'   => 'IDR',
            'is_active'  => true,
        ]);
    }
}

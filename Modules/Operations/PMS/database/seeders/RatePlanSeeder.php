<?php

namespace Modules\Operations\PMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\PMS\Models\RatePlan;

class RatePlanSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $currency = $property->currency ?? 'IDR';

        $plans = [
            [
                'rate_code'   => 'BAR',
                'rate_name'   => 'Best Available Rate',
                'plan_type'   => 'nightly',
                'base_rate'   => 850000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Standard non-restricted public rate. Best price available for walk-in and direct bookings.',
            ],
            [
                'rate_code'   => 'CORP',
                'rate_name'   => 'Corporate Rate',
                'plan_type'   => 'nightly',
                'base_rate'   => 720000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Negotiated rate for verified corporate accounts. Requires company ID at check-in.',
            ],
            [
                'rate_code'   => 'GOVT',
                'rate_name'   => 'Government Rate',
                'plan_type'   => 'nightly',
                'base_rate'   => 680000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Applicable to government employees and civil servants. Official ID required.',
            ],
            [
                'rate_code'   => 'PKG-BB',
                'rate_name'   => 'Bed & Breakfast Package',
                'plan_type'   => 'package',
                'base_rate'   => 950000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Nightly rate inclusive of daily breakfast for two adults.',
            ],
            [
                'rate_code'   => 'PKG-HB',
                'rate_name'   => 'Half Board Package',
                'plan_type'   => 'package',
                'base_rate'   => 1150000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Nightly rate inclusive of breakfast and dinner for two adults.',
            ],
            [
                'rate_code'   => 'DAY',
                'rate_name'   => 'Day Use Rate',
                'plan_type'   => 'day_use',
                'base_rate'   => 350000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Room access from 08:00 to 18:00. No overnight stay.',
            ],
            [
                'rate_code'   => 'OTA-EXP',
                'rate_name'   => 'OTA Rate — Expedia',
                'plan_type'   => 'nightly',
                'base_rate'   => 810000.00,
                'currency'    => $currency,
                'is_active'   => true,
                'description' => 'Rate loaded via Expedia channel manager. Managed via CM integration.',
            ],
            [
                'rate_code'   => 'PROMO-Q1',
                'rate_name'   => 'Q1 Promotional Rate',
                'plan_type'   => 'nightly',
                'base_rate'   => 650000.00,
                'currency'    => $currency,
                'is_active'   => false,
                'description' => 'Seasonal promotional rate for Q1. Not currently active.',
            ],
        ];

        foreach ($plans as $data) {
            RatePlan::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'rate_code'   => $data['rate_code'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

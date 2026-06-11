<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryUnit;

class InventoryUnitSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $units = [
            ['code' => 'PCS',  'name' => 'Piece'],
            ['code' => 'BOX',  'name' => 'Box'],
            ['code' => 'CTN',  'name' => 'Carton'],
            ['code' => 'KG',   'name' => 'Kilogram'],
            ['code' => 'G',    'name' => 'Gram'],
            ['code' => 'L',    'name' => 'Litre'],
            ['code' => 'ML',   'name' => 'Millilitre'],
            ['code' => 'ROLL', 'name' => 'Roll'],
            ['code' => 'PKT',  'name' => 'Packet'],
            ['code' => 'BTL',  'name' => 'Bottle'],
            ['code' => 'CAN',  'name' => 'Can'],
            ['code' => 'SET',  'name' => 'Set'],
            ['code' => 'PR',   'name' => 'Pair'],
            ['code' => 'M',    'name' => 'Metre'],
        ];

        foreach ($units as $data) {
            InventoryUnit::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'code'        => $data['code'],
                ],
                [
                    'property_id' => $property->id,
                    'code'        => $data['code'],
                    'name'        => $data['name'],
                ]
            );
        }
    }
}

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
            ['unit_code' => 'PCS',  'name' => 'Piece',      'abbreviation' => 'pcs'],
            ['unit_code' => 'BOX',  'name' => 'Box',        'abbreviation' => 'box'],
            ['unit_code' => 'CTN',  'name' => 'Carton',     'abbreviation' => 'ctn'],
            ['unit_code' => 'KG',   'name' => 'Kilogram',   'abbreviation' => 'kg'],
            ['unit_code' => 'G',    'name' => 'Gram',       'abbreviation' => 'g'],
            ['unit_code' => 'L',    'name' => 'Litre',      'abbreviation' => 'L'],
            ['unit_code' => 'ML',   'name' => 'Millilitre', 'abbreviation' => 'mL'],
            ['unit_code' => 'ROLL', 'name' => 'Roll',       'abbreviation' => 'roll'],
            ['unit_code' => 'PKT',  'name' => 'Packet',     'abbreviation' => 'pkt'],
            ['unit_code' => 'BTL',  'name' => 'Bottle',     'abbreviation' => 'btl'],
            ['unit_code' => 'CAN',  'name' => 'Can',        'abbreviation' => 'can'],
            ['unit_code' => 'SET',  'name' => 'Set',        'abbreviation' => 'set'],
            ['unit_code' => 'PR',   'name' => 'Pair',       'abbreviation' => 'pr'],
            ['unit_code' => 'M',    'name' => 'Metre',      'abbreviation' => 'm'],
        ];

        foreach ($units as $data) {
            InventoryUnit::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'unit_code'   => $data['unit_code'],
                ],
                array_merge($data, [
                    'property_id' => $property->id,
                    'is_active'   => true,
                ])
            );
        }
    }
}

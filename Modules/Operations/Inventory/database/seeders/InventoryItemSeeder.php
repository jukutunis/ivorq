<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;

class InventoryItemSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        // Resolve categories and units
        $cats  = InventoryCategory::where('property_id', $property->id)
            ->pluck('id', 'category_code');
        $units = InventoryUnit::where('property_id', $property->id)
            ->pluck('id', 'unit_code');

        if ($cats->isEmpty() || $units->isEmpty()) {
            $this->command->warn('InventoryItemSeeder: categories or units not found — run InventoryCategorySeeder and InventoryUnitSeeder first.');
            return;
        }

        $items = [
            // ── Housekeeping Amenities ──────────────────────────────────────────
            [
                'item_code'     => 'HK-SOAP-001',
                'name'          => 'Bath Soap Bar (30g)',
                'category_code' => 'HK-AMEN',
                'unit_code'     => 'PCS',
                'average_cost'  => '1200.0000',
                'reorder_point' => '500.000',
                'description'   => 'Individual wrapped guest bath soap bar.',
            ],
            [
                'item_code'     => 'HK-SHAMP-001',
                'name'          => 'Shampoo Sachet (10mL)',
                'category_code' => 'HK-AMEN',
                'unit_code'     => 'PCS',
                'average_cost'  => '3500.0000',
                'reorder_point' => '300.000',
                'description'   => 'Single-use shampoo sachet for guest rooms.',
            ],
            [
                'item_code'     => 'HK-COND-001',
                'name'          => 'Conditioner Sachet (10mL)',
                'category_code' => 'HK-AMEN',
                'unit_code'     => 'PCS',
                'average_cost'  => '3500.0000',
                'reorder_point' => '300.000',
                'description'   => 'Single-use conditioner sachet for guest rooms.',
            ],
            [
                'item_code'     => 'HK-TOWEL-001',
                'name'          => 'Bath Towel (White)',
                'category_code' => 'HK-AMEN',
                'unit_code'     => 'PCS',
                'average_cost'  => '85000.0000',
                'reorder_point' => '50.000',
                'description'   => '600gsm white bath towel — 70×140cm.',
            ],
            [
                'item_code'     => 'HK-LINEN-001',
                'name'          => 'Bed Sheet — Queen (White)',
                'category_code' => 'HK-AMEN',
                'unit_code'     => 'PCS',
                'average_cost'  => '120000.0000',
                'reorder_point' => '40.000',
                'description'   => '300TC percale queen fitted sheet.',
            ],
            [
                'item_code'     => 'HK-TISSUE-001',
                'name'          => 'Facial Tissue Box',
                'category_code' => 'HK-AMEN',
                'unit_code'     => 'BOX',
                'average_cost'  => '8500.0000',
                'reorder_point' => '200.000',
                'description'   => 'Premium 2-ply facial tissue — 200 sheets.',
            ],

            // ── Engineering Spare Parts ─────────────────────────────────────────
            [
                'item_code'     => 'ENG-BULB-LED01',
                'name'          => 'LED Bulb 9W E27',
                'category_code' => 'ENG-PARTS',
                'unit_code'     => 'PCS',
                'average_cost'  => '25000.0000',
                'reorder_point' => '20.000',
                'description'   => '9W E27 LED daylight bulb, 6500K.',
            ],
            [
                'item_code'     => 'ENG-FILT-AC01',
                'name'          => 'AC Filter — Split Unit',
                'category_code' => 'ENG-PARTS',
                'unit_code'     => 'PCS',
                'average_cost'  => '45000.0000',
                'reorder_point' => '10.000',
                'description'   => 'Washable filter for 1–2 ton split AC units.',
            ],
            [
                'item_code'     => 'ENG-TAPE-PTFE',
                'name'          => 'PTFE Thread Seal Tape',
                'category_code' => 'ENG-PARTS',
                'unit_code'     => 'ROLL',
                'average_cost'  => '5000.0000',
                'reorder_point' => '30.000',
                'description'   => '12mm × 10m PTFE plumber\'s tape.',
            ],
            [
                'item_code'     => 'ENG-BATT-AA',
                'name'          => 'AA Battery (Alkaline)',
                'category_code' => 'ENG-PARTS',
                'unit_code'     => 'PKT',
                'average_cost'  => '22000.0000',
                'reorder_point' => '15.000',
                'description'   => 'Alkaline AA battery — pack of 4.',
            ],

            // ── Laundry Supplies ────────────────────────────────────────────────
            [
                'item_code'     => 'LDY-DET-001',
                'name'          => 'Heavy-Duty Laundry Detergent (5kg)',
                'category_code' => 'LAUNDRY',
                'unit_code'     => 'BOX',
                'average_cost'  => '185000.0000',
                'reorder_point' => '5.000',
                'description'   => 'Commercial laundry detergent powder — 5kg box.',
            ],
            [
                'item_code'     => 'LDY-SOFT-001',
                'name'          => 'Fabric Softener (10L)',
                'category_code' => 'LAUNDRY',
                'unit_code'     => 'CTN',
                'average_cost'  => '220000.0000',
                'reorder_point' => '3.000',
                'description'   => 'Industrial fabric softener — 10L container.',
            ],
            [
                'item_code'     => 'LDY-STARCH-001',
                'name'          => 'Spray Starch (500mL)',
                'category_code' => 'LAUNDRY',
                'unit_code'     => 'BTL',
                'average_cost'  => '35000.0000',
                'reorder_point' => '8.000',
                'description'   => 'Ironing starch spray for linen finishing.',
            ],

            // ── Minibar Items ───────────────────────────────────────────────────
            [
                'item_code'     => 'MB-WATER-001',
                'name'          => 'Still Water 330mL',
                'category_code' => 'MINIBAR',
                'unit_code'     => 'BTL',
                'average_cost'  => '8000.0000',
                'reorder_point' => '100.000',
                'description'   => 'Mineral still water — 330mL plastic bottle.',
            ],
            [
                'item_code'     => 'MB-COLA-001',
                'name'          => 'Soft Drink 330mL Can',
                'category_code' => 'MINIBAR',
                'unit_code'     => 'CAN',
                'average_cost'  => '12000.0000',
                'reorder_point' => '60.000',
                'description'   => 'Assorted soft drink cans — 330mL.',
            ],
            [
                'item_code'     => 'MB-PNUT-001',
                'name'          => 'Salted Peanuts (50g)',
                'category_code' => 'MINIBAR',
                'unit_code'     => 'PKT',
                'average_cost'  => '15000.0000',
                'reorder_point' => '60.000',
                'description'   => 'Roasted salted peanuts — 50g sachet.',
            ],

            // ── Office Supplies ─────────────────────────────────────────────────
            [
                'item_code'     => 'OFF-PAPER-A4',
                'name'          => 'A4 Printing Paper (500 sheets)',
                'category_code' => 'OFFICE',
                'unit_code'     => 'PKT',
                'average_cost'  => '55000.0000',
                'reorder_point' => '20.000',
                'description'   => '80gsm white A4 copy paper — 500 sheet ream.',
            ],
            [
                'item_code'     => 'OFF-PEN-001',
                'name'          => 'Ballpoint Pen (Blue)',
                'category_code' => 'OFFICE',
                'unit_code'     => 'BOX',
                'average_cost'  => '35000.0000',
                'reorder_point' => '10.000',
                'description'   => 'Blue ink ballpoint pen — box of 12.',
            ],
            [
                'item_code'     => 'OFF-TONER-001',
                'name'          => 'Printer Toner Cartridge (Black)',
                'category_code' => 'OFFICE',
                'unit_code'     => 'PCS',
                'average_cost'  => '350000.0000',
                'reorder_point' => '2.000',
                'description'   => 'Compatible black toner cartridge — HP LaserJet.',
            ],
        ];

        foreach ($items as $data) {
            $categoryId = $cats[$data['category_code']] ?? null;
            $unitId     = $units[$data['unit_code']] ?? null;

            if (! $categoryId || ! $unitId) {
                continue;
            }

            InventoryItem::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'item_code'   => $data['item_code'],
                ],
                [
                    'property_id'   => $property->id,
                    'item_code'     => $data['item_code'],
                    'name'          => $data['name'],
                    'category_id'   => $categoryId,
                    'unit_id'       => $unitId,
                    'average_cost'  => $data['average_cost'],
                    'reorder_point' => $data['reorder_point'],
                    'description'   => $data['description'],
                    'is_active'     => true,
                ]
            );
        }
    }
}

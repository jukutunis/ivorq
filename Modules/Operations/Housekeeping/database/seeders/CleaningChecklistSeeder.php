<?php

namespace Modules\Operations\Housekeeping\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Models\ChecklistItem;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;

class CleaningChecklistSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $this->seedCheckoutChecklist($property->id);
        $this->seedStayoverChecklist($property->id);
        $this->seedPublicAreaChecklist($property->id);
    }

    private function seedCheckoutChecklist(string $propertyId): void
    {
        $checklist = CleaningChecklist::firstOrCreate(
            ['property_id' => $propertyId, 'name' => 'Checkout Cleaning Checklist'],
            [
                'property_id' => $propertyId,
                'task_type'   => 'checkout_cleaning',
                'description' => 'Standard checklist for room cleaning after guest checkout.',
                'is_active'   => true,
            ]
        );

        $items = [
            ['item_text' => 'Strip all beds and remove used linens',          'sort_order' => 1,  'is_required' => true],
            ['item_text' => 'Remove all used towels and bathroom amenities',   'sort_order' => 2,  'is_required' => true],
            ['item_text' => 'Empty and clean all waste bins',                  'sort_order' => 3,  'is_required' => true],
            ['item_text' => 'Clean and disinfect bathroom — toilet, sink, tub','sort_order' => 4,  'is_required' => true],
            ['item_text' => 'Wipe down all surfaces — desk, tables, fixtures', 'sort_order' => 5,  'is_required' => true],
            ['item_text' => 'Vacuum carpets and mop hard floors',              'sort_order' => 6,  'is_required' => true],
            ['item_text' => 'Make beds with fresh linens',                     'sort_order' => 7,  'is_required' => true],
            ['item_text' => 'Restock bathroom amenities and toiletries',       'sort_order' => 8,  'is_required' => true],
            ['item_text' => 'Check minibar — restock if needed',               'sort_order' => 9,  'is_required' => false],
            ['item_text' => 'Clean glass surfaces and mirrors',                'sort_order' => 10, 'is_required' => true],
            ['item_text' => 'Check for maintenance issues and report',         'sort_order' => 11, 'is_required' => false],
            ['item_text' => 'Final visual inspection before sign-off',         'sort_order' => 12, 'is_required' => true],
        ];

        $this->seedItems($checklist, $propertyId, $items);
    }

    private function seedStayoverChecklist(string $propertyId): void
    {
        $checklist = CleaningChecklist::firstOrCreate(
            ['property_id' => $propertyId, 'name' => 'Stayover Cleaning Checklist'],
            [
                'property_id' => $propertyId,
                'task_type'   => 'stayover_cleaning',
                'description' => 'Daily cleaning checklist for occupied rooms.',
                'is_active'   => true,
            ]
        );

        $items = [
            ['item_text' => 'Make beds and arrange pillows',                      'sort_order' => 1, 'is_required' => true],
            ['item_text' => 'Replace used towels — leave personal items in place', 'sort_order' => 2, 'is_required' => true],
            ['item_text' => 'Empty waste bins',                                    'sort_order' => 3, 'is_required' => true],
            ['item_text' => 'Clean and wipe down bathroom surfaces',               'sort_order' => 4, 'is_required' => true],
            ['item_text' => 'Replenish bathroom amenities as needed',              'sort_order' => 5, 'is_required' => true],
            ['item_text' => 'Vacuum carpets or sweep floors',                      'sort_order' => 6, 'is_required' => true],
            ['item_text' => 'Dust surfaces — avoid disturbing guest belongings',   'sort_order' => 7, 'is_required' => false],
            ['item_text' => 'Tidy any common areas without moving guest items',    'sort_order' => 8, 'is_required' => false],
        ];

        $this->seedItems($checklist, $propertyId, $items);
    }

    private function seedPublicAreaChecklist(string $propertyId): void
    {
        $checklist = CleaningChecklist::firstOrCreate(
            ['property_id' => $propertyId, 'name' => 'Public Area Cleaning Checklist'],
            [
                'property_id' => $propertyId,
                'task_type'   => 'public_area',
                'description' => 'Cleaning checklist for lobbies, corridors, and shared spaces.',
                'is_active'   => true,
            ]
        );

        $items = [
            ['item_text' => 'Sweep and mop all corridor floors',             'sort_order' => 1, 'is_required' => true],
            ['item_text' => 'Wipe down elevator panels and doors',           'sort_order' => 2, 'is_required' => true],
            ['item_text' => 'Clean lobby furniture and surfaces',            'sort_order' => 3, 'is_required' => true],
            ['item_text' => 'Empty all public waste bins',                   'sort_order' => 4, 'is_required' => true],
            ['item_text' => 'Clean and sanitise public restrooms',           'sort_order' => 5, 'is_required' => true],
            ['item_text' => 'Wipe down handrails and high-touch surfaces',   'sort_order' => 6, 'is_required' => true],
            ['item_text' => 'Polish glass doors and entrance panels',        'sort_order' => 7, 'is_required' => false],
            ['item_text' => 'Report any damage or maintenance needs',        'sort_order' => 8, 'is_required' => false],
        ];

        $this->seedItems($checklist, $propertyId, $items);
    }

    private function seedItems(CleaningChecklist $checklist, string $propertyId, array $items): void
    {
        foreach ($items as $item) {
            ChecklistItem::firstOrCreate(
                [
                    'checklist_id' => $checklist->id,
                    'item_text'    => $item['item_text'],
                ],
                array_merge($item, [
                    'checklist_id' => $checklist->id,
                    'property_id'  => $propertyId,
                ])
            );
        }
    }
}

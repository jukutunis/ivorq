<?php

namespace Modules\Operations\Engineering\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\EngineeringChecklistItem;

class EngineeringChecklistSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $this->seedWorkOrderChecklist($property->id);
        $this->seedPreventiveMaintenanceChecklist($property->id);
        $this->seedSafetyInspectionChecklist($property->id);
    }

    private function seedWorkOrderChecklist(string $propertyId): void
    {
        $checklist = EngineeringChecklist::firstOrCreate(
            ['property_id' => $propertyId, 'title' => 'Work Order Completion Checklist'],
            [
                'property_id'    => $propertyId,
                'checklist_type' => 'work_order',
                'description'    => 'Standard checklist for verifying work order completion quality.',
                'is_active'      => true,
            ]
        );

        $items = [
            ['item_text' => 'Confirm fault description matches the reported issue',         'sort_order' => 1,  'is_required' => true],
            ['item_text' => 'All tools and equipment accounted for before starting',        'sort_order' => 2,  'is_required' => true],
            ['item_text' => 'Safety precautions and PPE in place',                          'sort_order' => 3,  'is_required' => true],
            ['item_text' => 'Fault root cause identified and documented',                   'sort_order' => 4,  'is_required' => true],
            ['item_text' => 'Repair carried out as per manufacturer specifications',        'sort_order' => 5,  'is_required' => true],
            ['item_text' => 'Parts and materials used recorded in work order',              'sort_order' => 6,  'is_required' => false],
            ['item_text' => 'System or equipment tested and functioning correctly',         'sort_order' => 7,  'is_required' => true],
            ['item_text' => 'Work area cleaned and returned to original condition',         'sort_order' => 8,  'is_required' => true],
            ['item_text' => 'Guest or department notified of completion if applicable',     'sort_order' => 9,  'is_required' => false],
            ['item_text' => 'Follow-up work order raised if further action required',       'sort_order' => 10, 'is_required' => false],
            ['item_text' => 'Technician signature and completion time recorded',            'sort_order' => 11, 'is_required' => true],
        ];

        $this->seedItems($checklist, $propertyId, $items);
    }

    private function seedPreventiveMaintenanceChecklist(string $propertyId): void
    {
        $checklist = EngineeringChecklist::firstOrCreate(
            ['property_id' => $propertyId, 'title' => 'Preventive Maintenance Checklist'],
            [
                'property_id'    => $propertyId,
                'checklist_type' => 'preventive_maintenance',
                'description'    => 'Standard checklist for scheduled preventive maintenance tasks.',
                'is_active'      => true,
            ]
        );

        $items = [
            ['item_text' => 'Review previous PM report and pending action items',           'sort_order' => 1,  'is_required' => true],
            ['item_text' => 'Inspect general condition — signs of wear, corrosion, leaks',  'sort_order' => 2,  'is_required' => true],
            ['item_text' => 'Clean filters, vents, and accessible components',              'sort_order' => 3,  'is_required' => true],
            ['item_text' => 'Lubricate moving parts as per maintenance schedule',           'sort_order' => 4,  'is_required' => false],
            ['item_text' => 'Check and tighten all electrical connections',                 'sort_order' => 5,  'is_required' => true],
            ['item_text' => 'Test safety devices and emergency shutoffs',                   'sort_order' => 6,  'is_required' => true],
            ['item_text' => 'Verify operating parameters within acceptable range',          'sort_order' => 7,  'is_required' => true],
            ['item_text' => 'Replace consumables per schedule (belts, seals, filters)',     'sort_order' => 8,  'is_required' => false],
            ['item_text' => 'Document any anomalies or deterioration for follow-up',        'sort_order' => 9,  'is_required' => false],
            ['item_text' => 'Update maintenance log and next service date',                 'sort_order' => 10, 'is_required' => true],
        ];

        $this->seedItems($checklist, $propertyId, $items);
    }

    private function seedSafetyInspectionChecklist(string $propertyId): void
    {
        $checklist = EngineeringChecklist::firstOrCreate(
            ['property_id' => $propertyId, 'title' => 'Safety Inspection Checklist'],
            [
                'property_id'    => $propertyId,
                'checklist_type' => 'inspection',
                'description'    => 'Periodic safety inspection checklist for engineering systems.',
                'is_active'      => true,
            ]
        );

        $items = [
            ['item_text' => 'Fire extinguishers present, in date, and unobstructed',        'sort_order' => 1,  'is_required' => true],
            ['item_text' => 'Emergency exit signs illuminated and visible',                  'sort_order' => 2,  'is_required' => true],
            ['item_text' => 'Emergency lighting tested and functional',                      'sort_order' => 3,  'is_required' => true],
            ['item_text' => 'Fire alarm panel shows no faults',                              'sort_order' => 4,  'is_required' => true],
            ['item_text' => 'Sprinkler heads unobstructed and free of damage',               'sort_order' => 5,  'is_required' => true],
            ['item_text' => 'Electrical panels — no overheating, trips, or exposed wiring',  'sort_order' => 6,  'is_required' => true],
            ['item_text' => 'Gas installations — no leaks detected',                         'sort_order' => 7,  'is_required' => true],
            ['item_text' => 'Hazardous materials stored and labelled per regulations',       'sort_order' => 8,  'is_required' => true],
            ['item_text' => 'Safety signage in place for hazardous areas',                   'sort_order' => 9,  'is_required' => false],
            ['item_text' => 'First aid kits stocked and accessible',                         'sort_order' => 10, 'is_required' => false],
            ['item_text' => 'Issues noted, photographed, and reported to manager',           'sort_order' => 11, 'is_required' => false],
        ];

        $this->seedItems($checklist, $propertyId, $items);
    }

    private function seedItems(EngineeringChecklist $checklist, string $propertyId, array $items): void
    {
        foreach ($items as $item) {
            EngineeringChecklistItem::firstOrCreate(
                [
                    'engineering_checklist_id' => $checklist->id,
                    'item_text'                => $item['item_text'],
                ],
                array_merge($item, [
                    'engineering_checklist_id' => $checklist->id,
                    'property_id'              => $propertyId,
                ])
            );
        }
    }
}

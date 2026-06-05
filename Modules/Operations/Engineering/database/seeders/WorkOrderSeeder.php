<?php

namespace Modules\Operations\Engineering\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Zoning\Models\Zone;

class WorkOrderSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $room = Room::where('property_id', $property->id)->first();
        $zone = Zone::where('property_id', $property->id)->first();

        $workOrders = [
            [
                'work_order_number'  => 'WO-0001',
                'title'              => 'AC Unit Not Cooling — Room 101',
                'description'        => 'Guest reported air conditioning unit in room 101 is running but not producing cold air. Unit is making unusual noise.',
                'work_order_type'    => 'corrective',
                'priority'           => 1, // Critical
                'status'             => 'pending',
                'location_type'      => 'room',
                'room_id'            => $room?->id,
                'zone_id'            => null,
                'asset_description'  => 'Split AC Unit — Room 101',
                'sla_hours'          => 4.00,
                'estimated_hours'    => 2.00,
                'due_date'           => now()->addHours(4),
            ],
            [
                'work_order_number'  => 'WO-0002',
                'title'              => 'Water Leak — Bathroom Ceiling',
                'description'        => 'Water leak detected through bathroom ceiling in Room 203. Possible pipe burst from floor above.',
                'work_order_type'    => 'corrective',
                'priority'           => 1, // Critical
                'status'             => 'pending',
                'location_type'      => 'room',
                'room_id'            => $room?->id,
                'zone_id'            => null,
                'asset_description'  => 'Plumbing — Cold Water Supply Pipe',
                'sla_hours'          => 2.00,
                'estimated_hours'    => 3.00,
                'due_date'           => now()->addHours(2),
            ],
            [
                'work_order_number'  => 'WO-0003',
                'title'              => 'Guest Room TV Not Responding',
                'description'        => 'Guest in Room 105 reports television is unresponsive to remote and manual controls.',
                'work_order_type'    => 'guest_request',
                'priority'           => 3, // Normal
                'status'             => 'pending',
                'location_type'      => 'room',
                'room_id'            => $room?->id,
                'zone_id'            => null,
                'asset_description'  => 'Smart TV — Samsung 55"',
                'sla_hours'          => 24.00,
                'estimated_hours'    => 0.50,
                'due_date'           => now()->addHours(24),
            ],
            [
                'work_order_number'  => 'WO-0004',
                'title'              => 'Lobby Lighting Inspection',
                'description'        => 'Several LED panels in the main lobby are flickering. Full lighting audit required.',
                'work_order_type'    => 'inspection',
                'priority'           => 2, // High
                'status'             => 'pending',
                'location_type'      => 'zone',
                'room_id'            => null,
                'zone_id'            => $zone?->id,
                'asset_description'  => 'LED Lighting System — Main Lobby',
                'sla_hours'          => 48.00,
                'estimated_hours'    => 2.00,
                'due_date'           => now()->addHours(48),
            ],
            [
                'work_order_number'  => 'WO-0005',
                'title'              => 'Install Additional Power Outlets — Meeting Room A',
                'description'        => 'Install 4 additional power outlets and USB charging points at the conference table in Meeting Room A.',
                'work_order_type'    => 'installation',
                'priority'           => 3, // Normal
                'status'             => 'pending',
                'location_type'      => 'general',
                'room_id'            => null,
                'zone_id'            => $zone?->id,
                'asset_description'  => 'Electrical Outlets — Meeting Room A',
                'sla_hours'          => 72.00,
                'estimated_hours'    => 4.00,
                'due_date'           => now()->addHours(72),
            ],
        ];

        foreach ($workOrders as $data) {
            WorkOrder::firstOrCreate(
                [
                    'property_id'       => $property->id,
                    'work_order_number' => $data['work_order_number'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

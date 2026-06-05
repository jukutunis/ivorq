<?php

namespace Modules\Operations\Engineering\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Zoning\Models\Zone;

class PreventiveMaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $room = Room::where('property_id', $property->id)->first();
        $zone = Zone::where('property_id', $property->id)->first();

        $programs = [
            [
                'pm_code'           => 'PM-001',
                'title'             => 'HVAC Filter Replacement',
                'description'       => 'Monthly replacement of HVAC air filters across all guest room units.',
                'frequency'         => 'monthly',
                'status'            => 'active',
                'asset_description' => 'HVAC System — Guest Room Units',
                'estimated_hours'   => 4.00,
                'room_id'           => null,
                'zone_id'           => $zone?->id,
            ],
            [
                'pm_code'           => 'PM-002',
                'title'             => 'Fire Suppression System Inspection',
                'description'       => 'Quarterly inspection and testing of fire suppression system including sprinklers, detection devices, and alarm panels.',
                'frequency'         => 'quarterly',
                'status'            => 'active',
                'asset_description' => 'Fire Suppression System',
                'estimated_hours'   => 6.00,
                'room_id'           => null,
                'zone_id'           => null,
            ],
            [
                'pm_code'           => 'PM-003',
                'title'             => 'Elevator Maintenance',
                'description'       => 'Monthly maintenance of passenger elevator including lubrication, door mechanism check, and safety device testing.',
                'frequency'         => 'monthly',
                'status'            => 'active',
                'asset_description' => 'Passenger Elevator — Main Building',
                'estimated_hours'   => 3.00,
                'room_id'           => null,
                'zone_id'           => null,
            ],
            [
                'pm_code'           => 'PM-004',
                'title'             => 'Electrical Systems Annual Check',
                'description'       => 'Annual inspection of main electrical distribution boards, circuit breakers, and earthing systems.',
                'frequency'         => 'annual',
                'status'            => 'active',
                'asset_description' => 'Main Electrical Distribution System',
                'estimated_hours'   => 8.00,
                'room_id'           => null,
                'zone_id'           => null,
            ],
            [
                'pm_code'           => 'PM-005',
                'title'             => 'Swimming Pool Water Treatment',
                'description'       => 'Weekly pool water quality testing, chemical dosing, and filter backwashing.',
                'frequency'         => 'weekly',
                'status'            => 'active',
                'asset_description' => 'Swimming Pool System',
                'estimated_hours'   => 2.00,
                'room_id'           => null,
                'zone_id'           => $zone?->id,
            ],
        ];

        foreach ($programs as $data) {
            PreventiveMaintenance::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'pm_code'     => $data['pm_code'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

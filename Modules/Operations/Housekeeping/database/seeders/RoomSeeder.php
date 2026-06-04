<?php

namespace Modules\Operations\Housekeeping\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Zoning\Models\Zone;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        // Use first active zone if available
        $zone = Zone::where('property_id', $property->id)->first();

        $rooms = [
            // Floor 1
            ['room_number' => '101', 'room_name' => 'Garden View Standard',  'room_type' => 'standard', 'floor' => '1'],
            ['room_number' => '102', 'room_name' => 'Garden View Standard',  'room_type' => 'standard', 'floor' => '1'],
            ['room_number' => '103', 'room_name' => 'Garden View Deluxe',    'room_type' => 'deluxe',   'floor' => '1'],
            ['room_number' => '104', 'room_name' => 'Pool View Deluxe',      'room_type' => 'deluxe',   'floor' => '1'],
            ['room_number' => '105', 'room_name' => 'Corner Junior Suite',   'room_type' => 'suite',    'floor' => '1'],
            // Floor 2
            ['room_number' => '201', 'room_name' => 'City View Standard',    'room_type' => 'standard', 'floor' => '2'],
            ['room_number' => '202', 'room_name' => 'City View Standard',    'room_type' => 'standard', 'floor' => '2'],
            ['room_number' => '203', 'room_name' => 'City View Deluxe',      'room_type' => 'deluxe',   'floor' => '2'],
            ['room_number' => '204', 'room_name' => 'Sea View Deluxe',       'room_type' => 'deluxe',   'floor' => '2'],
            ['room_number' => '205', 'room_name' => 'Executive Suite',       'room_type' => 'suite',    'floor' => '2'],
            // Floor 3
            ['room_number' => '301', 'room_name' => 'Premier Suite',         'room_type' => 'suite',    'floor' => '3'],
            ['room_number' => '302', 'room_name' => 'Grand Villa',           'room_type' => 'villa',    'floor' => '3'],
        ];

        foreach ($rooms as $data) {
            Room::firstOrCreate(
                [
                    'property_id' => $property->id,
                    'room_number' => $data['room_number'],
                ],
                array_merge($data, [
                    'property_id'        => $property->id,
                    'zone_id'            => $zone?->id,
                    'cleanliness_status' => 'dirty',
                    'is_active'          => true,
                ])
            );
        }
    }
}

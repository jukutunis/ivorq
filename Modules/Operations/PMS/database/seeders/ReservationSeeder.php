<?php

namespace Modules\Operations\PMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;

class ReservationSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        // Resolve seed dependencies — fail gracefully if data is missing
        $guests   = Guest::where('property_id', $property->id)
            ->orderBy('guest_code')
            ->get()
            ->keyBy('guest_code');

        $ratePlans = RatePlan::where('property_id', $property->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('rate_code');

        $rooms = Room::where('property_id', $property->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('room_number');

        if ($guests->isEmpty()) {
            return;
        }

        $bar  = $ratePlans['BAR']  ?? $ratePlans->first();
        $corp = $ratePlans['CORP'] ?? $ratePlans->first();
        $bb   = $ratePlans['PKG-BB'] ?? $ratePlans->first();

        $g1 = $guests['GST-00001'] ?? $guests->first();
        $g2 = $guests['GST-00002'] ?? $guests->first();
        $g3 = $guests['GST-00003'] ?? $guests->first();
        $g4 = $guests['GST-00004'] ?? $guests->first();
        $g5 = $guests['GST-00005'] ?? $guests->first();
        $g6 = $guests['GST-00006'] ?? $guests->first();

        $r101 = $rooms['101'] ?? null;
        $r102 = $rooms['102'] ?? null;
        $r201 = $rooms['201'] ?? null;
        $r202 = $rooms['202'] ?? null;
        $r103 = $rooms['103'] ?? null;
        $r205 = $rooms['205'] ?? null;

        $reservations = [
            // Upcoming tentative — standard room, walk-in
            [
                'reservation_number' => 'RES-00001',
                'primary_guest_id'   => $g1?->id,
                'rate_plan_id'       => $bar?->id,
                'arrival_date'       => today()->addDays(2)->toDateString(),
                'departure_date'     => today()->addDays(4)->toDateString(),
                'nights'             => 2,
                'adults'             => 1,
                'children'           => 0,
                'reservation_source' => 'walk_in',
                'reserved_room_type' => 'standard',
                'status'             => 'tentative',
                'assigned_room_id'   => null,
                'remarks'            => 'Late check-in expected after 22:00.',
            ],
            // Upcoming confirmed — VIP, suite, with room assignment
            [
                'reservation_number' => 'RES-00002',
                'primary_guest_id'   => $g2?->id,
                'rate_plan_id'       => $bb?->id,
                'arrival_date'       => today()->addDay()->toDateString(),
                'departure_date'     => today()->addDays(5)->toDateString(),
                'nights'             => 4,
                'adults'             => 2,
                'children'           => 0,
                'reservation_source' => 'direct',
                'reserved_room_type' => 'suite',
                'status'             => 'confirmed',
                'assigned_room_id'   => $r205?->id,
                'remarks'            => 'VIP — champagne welcome amenity requested.',
            ],
            // Arriving today — corporate, confirmed
            [
                'reservation_number' => 'RES-00003',
                'primary_guest_id'   => $g3?->id,
                'rate_plan_id'       => $corp?->id,
                'arrival_date'       => today()->toDateString(),
                'departure_date'     => today()->addDays(3)->toDateString(),
                'nights'             => 3,
                'adults'             => 1,
                'children'           => 0,
                'reservation_source' => 'corporate',
                'reserved_room_type' => 'deluxe',
                'status'             => 'confirmed',
                'assigned_room_id'   => $r103?->id,
                'remarks'            => 'GlobalCorp — invoice to company.',
            ],
            // Currently checked-in — individual
            [
                'reservation_number' => 'RES-00004',
                'primary_guest_id'   => $g4?->id,
                'rate_plan_id'       => $bar?->id,
                'arrival_date'       => today()->subDay()->toDateString(),
                'departure_date'     => today()->addDays(2)->toDateString(),
                'nights'             => 3,
                'adults'             => 2,
                'children'           => 1,
                'reservation_source' => 'ota',
                'reserved_room_type' => 'standard',
                'status'             => 'checked_in',
                'assigned_room_id'   => $r102?->id,
                'remarks'            => 'Booked via Booking.com.',
            ],
            // Departing today — checked-in
            [
                'reservation_number' => 'RES-00005',
                'primary_guest_id'   => $g5?->id,
                'rate_plan_id'       => $bb?->id,
                'arrival_date'       => today()->subDays(2)->toDateString(),
                'departure_date'     => today()->toDateString(),
                'nights'             => 2,
                'adults'             => 2,
                'children'           => 0,
                'reservation_source' => 'direct',
                'reserved_room_type' => 'suite',
                'status'             => 'checked_in',
                'assigned_room_id'   => $r205?->id,
                'remarks'            => 'Late checkout requested (14:00).',
            ],
            // Future OTA — group
            [
                'reservation_number' => 'RES-00006',
                'primary_guest_id'   => $g6?->id,
                'rate_plan_id'       => $corp?->id,
                'arrival_date'       => today()->addDays(7)->toDateString(),
                'departure_date'     => today()->addDays(10)->toDateString(),
                'nights'             => 3,
                'adults'             => 1,
                'children'           => 0,
                'reservation_source' => 'corporate',
                'reserved_room_type' => 'deluxe',
                'status'             => 'tentative',
                'assigned_room_id'   => null,
                'remarks'            => 'Conference attendee — TechVentures block booking.',
            ],
            // Past checked-out
            [
                'reservation_number' => 'RES-00007',
                'primary_guest_id'   => $g1?->id,
                'rate_plan_id'       => $bar?->id,
                'arrival_date'       => today()->subDays(5)->toDateString(),
                'departure_date'     => today()->subDays(3)->toDateString(),
                'nights'             => 2,
                'adults'             => 1,
                'children'           => 0,
                'reservation_source' => 'walk_in',
                'reserved_room_type' => 'standard',
                'status'             => 'checked_out',
                'assigned_room_id'   => $r101?->id,
                'remarks'            => null,
            ],
        ];

        foreach ($reservations as $data) {
            if (! $data['primary_guest_id']) {
                continue;
            }

            Reservation::firstOrCreate(
                [
                    'property_id'        => $property->id,
                    'reservation_number' => $data['reservation_number'],
                ],
                array_merge($data, ['property_id' => $property->id])
            );
        }
    }
}

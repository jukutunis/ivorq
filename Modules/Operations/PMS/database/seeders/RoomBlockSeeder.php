<?php

namespace Modules\Operations\PMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Models\RoomBlock;

class RoomBlockSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();

        if (! $property) {
            return;
        }

        $rooms = Room::where('property_id', $property->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('room_number');

        if ($rooms->isEmpty()) {
            return;
        }

        $r301 = $rooms['301'] ?? $rooms->first();
        $r302 = $rooms['302'] ?? $rooms->first();

        $blocks = [
            // Grand Villa — long-term OOO for renovation
            [
                'room_id'    => $r302?->id,
                'block_type' => 'out_of_order',
                'status'     => 'active',
                'reason'     => 'maintenance',
                'notes'      => 'Full villa renovation — plumbing and electrical upgrade. Expected completion: +14 days.',
                'start_at'   => now()->startOfDay(),
                'end_at'     => now()->addDays(14)->endOfDay(),
            ],
            // Premier Suite — short OOS for deep cleaning
            [
                'room_id'    => $r301?->id,
                'block_type' => 'out_of_service',
                'status'     => 'active',
                'reason'     => 'cleaning',
                'notes'      => 'Post-event deep cleaning following long-stay VIP checkout.',
                'start_at'   => now()->startOfDay(),
                'end_at'     => now()->addDay()->setTime(12, 0),
            ],
        ];

        foreach ($blocks as $data) {
            if (! $data['room_id']) {
                continue;
            }

            // Idempotency: match on room + block_type + start_at (truncated to day)
            $exists = RoomBlock::where('property_id', $property->id)
                ->where('room_id', $data['room_id'])
                ->where('block_type', $data['block_type'])
                ->whereDate('start_at', today())
                ->where('status', 'active')
                ->exists();

            if (! $exists) {
                RoomBlock::create(array_merge($data, [
                    'property_id' => $property->id,
                ]));
            }
        }
    }
}

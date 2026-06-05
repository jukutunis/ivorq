<?php

namespace Modules\Operations\PMS\Listeners;

use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Events\GuestCheckedIn;

class UpdateRoomStatusToOccupied
{
    public function handle(GuestCheckedIn $event): void
    {
        $stay = $event->stay;

        $room = Room::withoutGlobalScopes()->find($stay->room_id);

        if (! $room) {
            return;
        }

        // Only transition if current occupancy allows it (vacant → occupied)
        if (RoomOccupancyStatusEnum::isValidTransition($room->occupancy_status, RoomOccupancyStatusEnum::Occupied)) {
            $room->update([
                'occupancy_status' => RoomOccupancyStatusEnum::Occupied,
            ]);
        }
    }
}

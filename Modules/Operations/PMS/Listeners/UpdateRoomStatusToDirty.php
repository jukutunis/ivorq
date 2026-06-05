<?php

namespace Modules\Operations\PMS\Listeners;

use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Events\GuestCheckedOut;

class UpdateRoomStatusToDirty
{
    public function handle(GuestCheckedOut $event): void
    {
        $stay = $event->stay;

        $room = Room::withoutGlobalScopes()->find($stay->room_id);

        if (! $room) {
            return;
        }

        $updates = [];

        // Transition occupancy: occupied → vacant
        if (RoomOccupancyStatusEnum::isValidTransition($room->occupancy_status, RoomOccupancyStatusEnum::Vacant)) {
            $updates['occupancy_status'] = RoomOccupancyStatusEnum::Vacant;
        }

        // Transition cleanliness to dirty — valid from clean or inspected
        if ($room->cleanliness_status?->canTransitionTo(RoomCleanlinessStatusEnum::Dirty)) {
            $updates['cleanliness_status'] = RoomCleanlinessStatusEnum::Dirty;
        } elseif ($room->cleanliness_status === null) {
            // No prior cleanliness status — set dirty directly
            $updates['cleanliness_status'] = RoomCleanlinessStatusEnum::Dirty;
        }

        if (! empty($updates)) {
            $room->update($updates);
        }
    }
}

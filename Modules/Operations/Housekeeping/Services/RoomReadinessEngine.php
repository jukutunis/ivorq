<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Models\Room;

class RoomReadinessEngine
{
    public function calculateReadiness(Room $room): string
    {
        // Simple mock of the state engine
        if ($room->cleanliness_status === 'ooo' || $room->cleanliness_status === 'oos') {
            return 'blocked';
        }

        if ($room->cleanliness_status === 'dirty') {
            return 'waiting_cleaning';
        }

        if ($room->cleanliness_status === 'clean') {
            return 'waiting_inspection';
        }

        if ($room->cleanliness_status === 'inspected') {
            if ($room->is_vip) {
                return 'ready_for_vip';
            }
            
            if ($room->occupancy_status === 'arrival') {
                return 'ready_for_arrival';
            }
            
            return 'ready_for_sale';
        }

        return 'unknown';
    }
}
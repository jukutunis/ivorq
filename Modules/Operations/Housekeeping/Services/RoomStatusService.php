<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomStatusHistory;

class RoomStatusService
{
    private RoomReadinessEngine $readinessEngine;

    public function __construct(RoomReadinessEngine $readinessEngine)
    {
        $this->readinessEngine = $readinessEngine;
    }

    public function updateStatus(Room $room, string $newCleanlinessStatus, string $reason = null, string $changedBy = null): Room
    {
        $oldStatus = $room->cleanliness_status;
        
        $room->cleanliness_status = $newCleanlinessStatus;
        $room->readiness_state = $this->readinessEngine->calculateReadiness($room);
        $room->save();

        RoomStatusHistory::create([
            'room_id' => $room->id,
            'old_status' => $oldStatus,
            'new_status' => $newCleanlinessStatus,
            'reason' => $reason,
            'changed_by' => $changedBy,
        ]);

        return $room;
    }
}
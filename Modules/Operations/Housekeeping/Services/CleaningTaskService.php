<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;

class CleaningTaskService
{
    public function generateDepartureTask(Room $room): CleaningTask
    {
        return CleaningTask::create([
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'task_type' => 'departure',
            'status' => 'pending',
            'priority' => $room->is_vip ? 'rush' : 'normal',
            'credits' => 1.0,
            'sla_minutes_target' => 45,
        ]);
    }
    
    public function generateTurndownTask(Room $room): CleaningTask
    {
        return CleaningTask::create([
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'task_type' => 'turndown',
            'status' => 'pending',
            'priority' => 'normal',
            'credits' => 0.5,
            'sla_minutes_target' => 15,
        ]);
    }
}
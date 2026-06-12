<?php

$dir = __DIR__ . '/../../Modules/Operations/Housekeeping/Services';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$services = [
    'RoomReadinessEngine.php' => <<<'PHP'
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
PHP,

    'RoomStatusService.php' => <<<'PHP'
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
PHP,

    'CleaningTaskService.php' => <<<'PHP'
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
PHP,
];

foreach ($services as $filename => $content) {
    file_put_contents($dir . '/' . $filename, $content);
}

echo "Housekeeping v2.6 Services generated.\n";

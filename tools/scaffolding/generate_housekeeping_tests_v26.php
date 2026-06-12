<?php

$dir = __DIR__ . '/../../Modules/Operations/Housekeeping/tests/Feature';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$tests = [
    'RoomReadinessTest.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Services\RoomStatusService;
use Modules\Operations\Housekeeping\Services\RoomReadinessEngine;

class RoomReadinessTest extends TestCase
{
    use RefreshDatabase;

    private RoomStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomStatusService(new RoomReadinessEngine());
    }

    public function test_room_readiness_engine_updates_state()
    {
        $property = Property::factory()->create();
        $room = Room::create([
            'property_id' => $property->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
        ]);

        $this->service->updateStatus($room, 'clean');
        $this->assertEquals('waiting_inspection', $room->fresh()->readiness_state);

        $this->service->updateStatus($room, 'inspected');
        $this->assertEquals('ready_for_sale', $room->fresh()->readiness_state);

        $room->update(['occupancy_status' => 'arrival']);
        $this->service->updateStatus($room, 'inspected');
        $this->assertEquals('ready_for_arrival', $room->fresh()->readiness_state);
        
        $room->update(['is_vip' => true]);
        $this->service->updateStatus($room, 'inspected');
        $this->assertEquals('ready_for_vip', $room->fresh()->readiness_state);
        
        $this->service->updateStatus($room, 'ooo');
        $this->assertEquals('blocked', $room->fresh()->readiness_state);
    }
}
PHP,

    'CleaningTaskTest.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Modules\Foundation\Property\Models\Property;

class CleaningTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleaning_task_service_generates_tasks()
    {
        $property = Property::factory()->create();
        $room = Room::create([
            'property_id' => $property->id,
            'room_number' => '102',
            'room_type' => 'Suite',
            'is_vip' => true,
        ]);

        $service = new CleaningTaskService();
        $task = $service->generateDepartureTask($room);

        $this->assertEquals('departure', $task->task_type);
        $this->assertEquals('rush', $task->priority);
        $this->assertEquals(45, $task->sla_minutes_target);
    }
}
PHP,
];

foreach ($tests as $filename => $content) {
    file_put_contents($dir . '/' . $filename, $content);
}

echo "Housekeeping v2.6 Tests generated.\n";

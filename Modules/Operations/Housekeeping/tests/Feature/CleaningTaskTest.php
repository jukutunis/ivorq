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
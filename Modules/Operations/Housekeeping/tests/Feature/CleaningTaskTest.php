<?php

namespace Modules\Operations\Housekeeping\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;

class CleaningTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleaning_task_service_generates_tasks()
    {
        $company = \Modules\Foundation\Property\Models\Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'is_active' => true,
        ]);
        $property = \Modules\Foundation\Property\Models\Property::create([
            'company_id' => $company->id,
            'name' => 'Test Property',
            'slug' => 'test-property',
            'code' => 'TP1',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $room = Room::create([
            'property_id' => $property->id,
            'room_number' => '102',
            'room_type' => 'suite',
            'is_vip' => true,
        ]);

        $service = new CleaningTaskService();
        $task = $service->generateDepartureTask($room);

        $this->assertEquals('checkout_cleaning', $task->task_type->value);
        $this->assertEquals('rush', $task->priority);
        $this->assertEquals(45, $task->sla_minutes_target);
    }
}
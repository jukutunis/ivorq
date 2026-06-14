<?php

namespace Modules\Foundation\Task\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\Task\Models\Task;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class TaskModuleTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_super_admin_can_create_task(): void
    {
        $admin = $this->createSuperAdmin();
        $company = $this->createCompany();
        $property = $this->createProperty($company);

        $this->actingAs($admin);

        $task = Task::create([
            'property_id' => $property->id,
            'title' => 'Test task',
            'description' => 'Test description',
            'status' => TaskStatusEnum::Open->value,
            'priority' => 'normal',
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Test task',
        ]);
    }

    public function test_task_has_property_isolation(): void
    {
        $company = $this->createCompany();
        $prop1 = $this->createProperty($company);
        $prop2 = $this->createProperty($company, ['code' => 'P2']);

        $task1 = Task::create([
            'property_id' => $prop1->id,
            'title' => 'Task for Prop 1',
        ]);

        $task2 = Task::create([
            'property_id' => $prop2->id,
            'title' => 'Task for Prop 2',
        ]);

        // When authenticated as admin of prop 1
        $admin = $this->createPropertyAdmin($prop1);
        $this->actingAs($admin);

        // Due to BelongsToProperty global scope, we should only see task1
        $visibleTasks = Task::all();

        $this->assertCount(1, $visibleTasks);
        $this->assertEquals($task1->id, $visibleTasks->first()->id);
    }
}

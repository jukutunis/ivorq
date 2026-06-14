<?php

namespace Modules\Foundation\Notification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Foundation\Notification\Listeners\TaskEventListener;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Foundation\User\Models\User;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class TaskEventIntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected function setUp(): void
    {
        parent::setUp();
        // Register the event listener since it's not loaded in testing setup normally unless AppServiceProvider does it
        // We know AppServiceProvider does it, but let's be sure
    }

    public function test_task_assigned_creates_notification(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);
        $staff = $this->createUser();

        $this->actingAs($admin);

        $task = Task::create([
            'property_id' => $property->id,
            'title' => 'Clean Lobby',
            'status' => 'open',
            'priority' => 'high',
        ]);

        $service = app(TaskService::class);
        $service->assignTask($task->id, User::class, $staff->id);

        $this->assertDatabaseHas('app_notifications', [
            'property_id' => $property->id,
            'user_id' => $staff->id,
            'type' => 'TaskAssigned',
            'title' => 'New task assigned: Clean Lobby',
        ]);
    }

    public function test_task_completed_creates_notification(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);
        
        $this->actingAs($admin);

        $task = Task::create([
            'property_id' => $property->id,
            'title' => 'Fix AC',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $service = app(TaskService::class);
        $service->completeTask($task->id);

        $this->assertDatabaseHas('app_notifications', [
            'property_id' => $property->id,
            'user_id' => $admin->id,
            'type' => 'TaskCompleted',
            'title' => 'Task completed: Fix AC',
        ]);
    }
}

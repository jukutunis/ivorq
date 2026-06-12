<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class CleaningTaskModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    private function makeTask(array $property, string $code = 'TSK-M01', array $extra = []): CleaningTask
    {
        return app(CleaningTaskService::class)->create(array_merge([
            'property_id' => $property['id'],
            'task_code'   => $code,
            'title'       => "Task {$code}",
            'task_type'   => TaskTypeEnum::CheckoutCleaning->value,
            'priority'    => 3,
        ], $extra));
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_task_status_defaults_to_pending(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task = $this->makeTask($property->toArray(), 'TSK-C01');

        $this->assertSame(TaskStatusEnum::Pending, $task->status);
        $this->assertDatabaseHas('cleaning_tasks', [
            'property_id' => $property->id,
            'task_code'   => 'TSK-C01',
            'status'      => 'pending',
        ]);
    }

    // ── Assign ────────────────────────────────────────────────────────────────

    public function test_assign_task_creates_active_assignment_and_transitions_status(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);
        $task    = $this->makeTask($property->toArray(), 'TSK-A01');

        $assignment = $service->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $this->assertSame(AssignmentStatusEnum::Active, $assignment->status);
        $this->assertDatabaseHas('task_assignments', [
            'cleaning_task_id' => $task->id,
            'user_id'          => $admin->id,
            'status'           => 'active',
        ]);

        $this->assertSame(TaskStatusEnum::Assigned, $service->find($task->id)->status);
    }

    // ── Start ─────────────────────────────────────────────────────────────────

    public function test_start_task_records_started_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);
        $task    = $this->makeTask($property->toArray(), 'TSK-S01');

        $service->changeStatus($task->id, TaskStatusEnum::Assigned);
        $started = $service->changeStatus($task->id, TaskStatusEnum::InProgress);

        $this->assertSame(TaskStatusEnum::InProgress, $started->status);
        $this->assertNotNull($started->started_at);
    }

    // ── Complete ──────────────────────────────────────────────────────────────

    public function test_complete_task_records_completed_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);
        $task    = $this->makeTask($property->toArray(), 'TSK-CP1');

        $service->changeStatus($task->id, TaskStatusEnum::Assigned);
        $service->changeStatus($task->id, TaskStatusEnum::InProgress);
        $completed = $service->changeStatus($task->id, TaskStatusEnum::Completed);

        $this->assertSame(TaskStatusEnum::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_pending_task_transitions_to_cancelled(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service  = app(CleaningTaskService::class);
        $task     = $this->makeTask($property->toArray(), 'TSK-CX1');
        $cancelled = $service->changeStatus($task->id, TaskStatusEnum::Cancelled);

        $this->assertSame(TaskStatusEnum::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('cleaning_tasks', [
            'id'     => $task->id,
            'status' => 'cancelled',
        ]);
    }

    // ── Invalid transition ────────────────────────────────────────────────────

    public function test_invalid_status_transition_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);
        $task    = $this->makeTask($property->toArray(), 'TSK-IV1');

        // pending → completed skips required steps
        $this->expectException(ValidationException::class);
        $service->changeStatus($task->id, TaskStatusEnum::Completed);
    }

    // ── completed_by ─────────────────────────────────────────────────────────

    public function test_completed_by_is_set_to_authenticated_user(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);
        $task    = $this->makeTask($property->toArray(), 'TSK-CB1');

        $service->changeStatus($task->id, TaskStatusEnum::Assigned);
        $service->changeStatus($task->id, TaskStatusEnum::InProgress);
        $completed = $service->changeStatus($task->id, TaskStatusEnum::Completed);

        $this->assertSame($admin->id, $completed->completed_by);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_task_policy_denies_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB20']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $task = CleaningTask::create([
            'property_id' => $propertyA->id,
            'task_code'   => 'TSK-XP1',
            'title'       => 'Cross-property task',
            'task_type'   => 'custom',
            'status'      => 'pending',
            'priority'    => 3,
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $task)->denied());
        $this->assertTrue(Gate::inspect('delete', $task)->denied());
    }

    // ── Activity log ─────────────────────────────────────────────────────────

    public function test_create_task_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task = $this->makeTask($property->toArray(), 'TSK-AL1');

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => CleaningTask::class,
            'subject_id'   => $task->id,
        ]);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_create_task_writes_audit_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task = CleaningTask::create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-AU1',
            'title'       => 'Audit Test',
            'task_type'   => 'custom',
            'status'      => 'pending',
            'priority'    => 3,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => CleaningTask::class,
            'auditable_id'   => $task->id,
            'event'          => 'created',
        ]);
    }
}

<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Modules\Operations\Housekeeping\Services\TaskAssignmentService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class TaskAssignmentModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    private function makeTask(string $propertyId, string $code): CleaningTask
    {
        return CleaningTask::create([
            'property_id' => $propertyId,
            'task_code'   => $code,
            'title'       => "Task {$code}",
            'task_type'   => 'checkout_cleaning',
            'status'      => 'pending',
            'priority'    => 3,
        ]);
    }

    // ── Assign ────────────────────────────────────────────────────────────────

    public function test_assign_user_to_task_creates_active_assignment(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task       = $this->makeTask($property->id, 'ASN-01');
        $assignment = app(CleaningTaskService::class)->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $this->assertInstanceOf(TaskAssignment::class, $assignment);
        $this->assertSame(AssignmentStatusEnum::Active, $assignment->status);
        $this->assertNotNull($assignment->assigned_at);
        $this->assertDatabaseHas('housekeeping_task_assignments', [
            'cleaning_task_id' => $task->id,
            'user_id'          => $admin->id,
            'department_id'    => $department->id,
            'status'           => 'active',
        ]);
    }

    // ── Complete ──────────────────────────────────────────────────────────────

    public function test_complete_assignment_updates_status_and_completed_at(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task       = $this->makeTask($property->id, 'ASN-02');
        $assignment = app(CleaningTaskService::class)->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $completed = app(TaskAssignmentService::class)->complete($assignment->id);

        $this->assertSame(AssignmentStatusEnum::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertDatabaseHas('housekeeping_task_assignments', [
            'id'     => $assignment->id,
            'status' => 'completed',
        ]);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_assignment_sets_cancelled_status(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task       = $this->makeTask($property->id, 'ASN-03');
        $assignment = app(CleaningTaskService::class)->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $cancelled = app(TaskAssignmentService::class)->cancel($assignment->id);

        $this->assertSame(AssignmentStatusEnum::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('housekeeping_task_assignments', [
            'id'     => $assignment->id,
            'status' => 'cancelled',
        ]);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_assignment_policy_denies_update_and_delete(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB30']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $deptA     = $this->createDepartment($propertyA);

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $task = $this->makeTask($propertyA->id, 'ASN-XP');
        $task->update(['status' => 'assigned']);

        $assignment = TaskAssignment::create([
            'property_id'      => $propertyA->id,
            'cleaning_task_id' => $task->id,
            'user_id'          => $adminB->id,
            'department_id'    => $deptA->id,
            'assigned_at'      => now(),
            'status'           => 'active',
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $assignment)->denied());
        $this->assertTrue(Gate::inspect('delete', $assignment)->denied());
    }
}

<?php

namespace Tests\Feature\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Shared\Services\CurrentPropertyService;
use Inertia\Testing\AssertableInertia as Assert;



class HousekeepingTaskInspectionSliceOneTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Foundation\Concerns\CreatesFoundationData;

    protected User $supervisor;
    protected User $attendant;
    protected User $otherAttendant;
    protected Property $property;
    protected Property $otherProperty;
    protected Room $room;
    protected Room $otherRoom;
    protected Department $department;
    protected Department $otherDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);



        // Set up companies, properties, users, permissions
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);

        $company2 = $this->createCompany();
        $this->otherProperty = $this->createProperty($company2, ['code' => 'OTH']);

        $this->supervisor = $this->createUser($this->property, 'property-admin');
        $this->supervisor->givePermissionTo([
            'housekeeping.task.view',
            'housekeeping.task.create',
            'housekeeping.task.edit',
            'housekeeping.task.assign',
            'housekeeping.task.delete',
            'housekeeping.inspection.view',
            'housekeeping.inspection.create',
            'housekeeping.inspection.conduct',
            'housekeeping.inspection.approve',
            'housekeeping.room.view',
            'housekeeping.room.cleanliness',
        ]);

        $this->attendant = $this->createUser($this->property, 'staff');
        $this->attendant->givePermissionTo([
            'housekeeping.task.view',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.room.view',
        ]);

        $this->otherAttendant = $this->createUser($this->property, 'staff');
        $this->otherAttendant->givePermissionTo([
            'housekeeping.task.view',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.room.view',
        ]);

        $this->department = $this->createDepartment($this->property);
        $this->otherDepartment = $this->createDepartment($this->otherProperty);

        // Rooms
        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '201',
            'room_type' => 'standard',
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
        ]);

        $this->otherRoom = Room::create([
            'property_id' => $this->otherProperty->id,
            'room_number' => '202',
            'room_type' => 'standard',
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
        ]);

        // Default session setup for active property context validation
        session([
            'current_property_id' => $this->property->id,
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
        ]);
    }

    public function test_same_property_supervisor_creates_new_room_task()
    {
        $response = $this->actingAs($this->supervisor)
            ->postJson('/operations/cleaning-tasks', [
                'room_id' => $this->room->id,
                'title' => 'Departure Clean',
                'task_type' => 'checkout_cleaning',
                'task_code' => 'HK-001',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('cleaning_tasks', [
            'room_id' => $this->room->id,
            'title' => 'Departure Clean',
            'status' => 'pending',
            'property_id' => $this->property->id,
        ]);
    }

    public function test_same_property_supervisor_assigns_room_attendant()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-002',
            'title' => 'Departure Clean',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        $response = $this->actingAs($this->supervisor)
            ->postJson("/operations/cleaning-tasks/{$task->id}/assign", [
                'user_id' => $this->attendant->id,
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEquals(TaskStatusEnum::Assigned, $task->fresh()->status);
        $this->assertDatabaseHas('housekeeping_task_assignments', [
            'cleaning_task_id' => $task->id,
            'user_id' => $this->attendant->id,
            'status' => 'active',
        ]);
    }

    public function test_assigned_attendant_starts_cleaning()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-003',
            'title' => 'Departure Clean',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        TaskAssignment::create([
            'cleaning_task_id' => $task->id,
            'user_id' => $this->attendant->id,
            'department_id' => $this->department->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);
        $task->update(['status' => 'assigned']);

        $response = $this->actingAs($this->attendant)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'in_progress',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEquals(TaskStatusEnum::InProgress, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->started_at);
    }

    public function test_different_attendant_cannot_start_or_complete()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-004',
            'title' => 'Departure Clean',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        TaskAssignment::create([
            'cleaning_task_id' => $task->id,
            'user_id' => $this->attendant->id,
            'department_id' => $this->department->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);
        $task->update(['status' => 'assigned']);

        // Other attendant tries to start
        $response = $this->actingAs($this->otherAttendant)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'in_progress',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500); // throws Exception: "Only the active assigned room attendant..."
        $this->assertEquals(TaskStatusEnum::Assigned, $task->fresh()->status);

        // Put task to in_progress to test complete attempt
        $task->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // Other attendant tries to complete
        $response = $this->actingAs($this->otherAttendant)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'completed',
                'remarks' => 'Done',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500);
        $this->assertEquals(TaskStatusEnum::InProgress, $task->fresh()->status);
    }

    public function test_assigned_attendant_completes_cleaning_with_note()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-005',
            'title' => 'Departure Clean',
            'status' => 'in_progress',
            'task_type' => 'checkout_cleaning',
            'started_at' => now(),
        ]);

        TaskAssignment::create([
            'cleaning_task_id' => $task->id,
            'user_id' => $this->attendant->id,
            'department_id' => $this->department->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->attendant)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'completed',
                'remarks' => 'Completed all items, clean and fresh.',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cleaning_tasks', [
            'id' => $task->id,
            'status' => 'completed',
            'notes' => 'Completed all items, clean and fresh.',
            'completed_by' => $this->attendant->id,
        ]);

        // Room cleanliness should be clean, readiness should be waiting_inspection
        $this->assertEquals(\Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum::Clean, $this->room->fresh()->cleanliness_status);
        $this->assertEquals('waiting_inspection', $this->room->fresh()->readiness_state);

        // A pending inspection record should be created
        $this->assertDatabaseHas('room_inspections', [
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'cleaning_task_id' => $task->id,
            'status' => 'pending',
        ]);
    }

    public function test_missing_completion_note_fails_without_status_mutation()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-006',
            'title' => 'Departure Clean',
            'status' => 'in_progress',
            'task_type' => 'checkout_cleaning',
            'started_at' => now(),
        ]);

        TaskAssignment::create([
            'cleaning_task_id' => $task->id,
            'user_id' => $this->attendant->id,
            'department_id' => $this->department->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->attendant)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'completed',
                'remarks' => ' ', // empty note
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500); // fails
        $this->assertEquals(TaskStatusEnum::InProgress, $task->fresh()->status);
    }

    public function test_supervisor_passes_inspection()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-007',
            'title' => 'Departure Clean',
            'status' => 'completed',
            'task_type' => 'checkout_cleaning',
            'completed_at' => now(),
        ]);

        $inspection = RoomInspection::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'cleaning_task_id' => $task->id,
            'status' => 'pending',
            'inspection_type' => 'post_cleaning',
        ]);

        $this->room->update([
            'cleanliness_status' => 'clean',
            'readiness_state' => 'waiting_inspection',
        ]);

        $response = $this->actingAs($this->supervisor)
            ->postJson("/operations/inspections/{$inspection->id}/pass", [
                'remarks' => 'Excellent work.',
                'inspection_severity' => 'minor',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);

        // Inspection evidence persists
        $this->assertDatabaseHas('room_inspections', [
            'id' => $inspection->id,
            'status' => 'passed',
            'is_passed' => true,
            'remarks' => 'Excellent work.',
            'supervisor_id' => $this->supervisor->id,
        ]);

        // Task reaches Ready-compatible status (verified_at timestamp is set)
        $this->assertNotNull($task->fresh()->verified_at);

        // Room cleanliness reaches inspected, readiness reaches ready_for_sale
        $this->assertEquals(\Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum::Inspected, $this->room->fresh()->cleanliness_status);
        $this->assertEquals('ready_for_sale', $this->room->fresh()->readiness_state);
    }

    public function test_supervisor_fails_inspection()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-008',
            'title' => 'Departure Clean',
            'status' => 'completed',
            'task_type' => 'checkout_cleaning',
            'completed_at' => now(),
        ]);

        $inspection = RoomInspection::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'cleaning_task_id' => $task->id,
            'status' => 'pending',
            'inspection_type' => 'post_cleaning',
        ]);

        $this->room->update([
            'cleanliness_status' => 'clean',
            'readiness_state' => 'waiting_inspection',
        ]);

        $response = $this->actingAs($this->supervisor)
            ->postJson("/operations/inspections/{$inspection->id}/fail", [
                'remarks' => 'Dust found on TV.',
                'inspection_severity' => 'minor',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);

        // Inspection evidence persists with is_passed = false
        $this->assertDatabaseHas('room_inspections', [
            'id' => $inspection->id,
            'status' => 'failed',
            'is_passed' => false,
            'remarks' => 'Dust found on TV.',
            'supervisor_id' => $this->supervisor->id,
        ]);

        // Room cleanliness goes back to dirty, readiness waiting_cleaning
        $this->assertEquals(\Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum::Dirty, $this->room->fresh()->cleanliness_status);
        $this->assertEquals('waiting_cleaning', $this->room->fresh()->readiness_state);

        // Task must not become verified
        $this->assertNull($task->fresh()->verified_at);
    }

    public function test_cross_property_attempts_fail()
    {
        // 1. Cross-property create task attempt
        $response = $this->actingAs($this->supervisor)
            ->postJson('/operations/cleaning-tasks', [
                'room_id' => $this->otherRoom->id,
                'title' => 'Departure Clean',
                'task_type' => 'checkout_cleaning',
                'task_code' => 'HK-009',
            ], ['X-Property-ID' => $this->property->id]); // Request is for property, but room is in otherProperty

        $response->assertStatus(422); // Validation rule exists in rooms check: Rule::exists('rooms', 'id')->where('property_id', $propertyId)

        // 2. Cross-property assign attempt
        $task = CleaningTask::create([
            'property_id' => $this->otherProperty->id,
            'room_id' => $this->otherRoom->id,
            'task_code' => 'HK-010',
            'title' => 'Departure Clean',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        $response = $this->actingAs($this->supervisor)
            ->postJson("/operations/cleaning-tasks/{$task->id}/assign", [
                'user_id' => $this->attendant->id,
                'department_id' => $this->department->id,
            ], ['X-Property-ID' => $this->property->id]); // Try to assign otherProperty's task with property header

        $response->assertStatus(403); // Property context mismatch in controller/policy

        // 3. Cross-property status transition attempt
        $task->update(['status' => 'assigned']);
        TaskAssignment::create([
            'cleaning_task_id' => $task->id,
            'user_id' => $this->attendant->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        // Attempting transition using supervisor or attendant of property, but targeting otherProperty in header
        $response = $this->actingAs($this->supervisor)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'in_progress',
            ], ['X-Property-ID' => $this->otherProperty->id]);

        $response->assertStatus(403); // Property context mismatch or middleware failure

        // 4. Cross-property inspection attempt
        $inspection = RoomInspection::create([
            'property_id' => $this->otherProperty->id,
            'room_id' => $this->otherRoom->id,
            'cleaning_task_id' => $task->id,
            'status' => 'pending',
            'inspection_type' => 'post_cleaning',
        ]);

        $response = $this->actingAs($this->supervisor)
            ->postJson("/operations/inspections/{$inspection->id}/pass", [
                'remarks' => 'Good',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403); // Property context mismatch
    }

    public function test_engineering_style_lifecycle_bypass_is_impossible()
    {
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-011',
            'title' => 'Departure Clean',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        // Attempting to skip Assigned/In Progress directly to Completed
        $response = $this->actingAs($this->attendant)
            ->postJson("/operations/cleaning-tasks/{$task->id}/status", [
                'status' => 'completed',
                'remarks' => 'Sneaky bypass attempt',
            ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(422); // ValidationException: Invalid status transition
        $this->assertEquals(TaskStatusEnum::Pending, $task->fresh()->status);
    }

    public function test_housekeeping_inertia_page_receives_only_active_property_live_data()
    {
        // Set the active property ID on the session
        session([
            'current_property_id' => $this->property->id,
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
        ]);

        // Task for active property
        $activeTask = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $this->room->id,
            'task_code' => 'HK-ACT',
            'title' => 'Active Property Task',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        // Task for other property
        $otherTask = CleaningTask::create([
            'property_id' => $this->otherProperty->id,
            'room_id' => $this->otherRoom->id,
            'task_code' => 'HK-OTH',
            'title' => 'Other Property Task',
            'status' => 'pending',
            'task_type' => 'checkout_cleaning',
        ]);

        $response = $this->actingAs($this->supervisor)
            ->get('/housekeeping/room-board');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Ivorq/Housekeeping/HousekeepingWorkspace')
            ->has('tasks', 1)
            ->where('tasks.0.id', $activeTask->id)
            ->has('rooms', 1)
            ->where('rooms.0.id', $this->room->id)
        );
    }
}

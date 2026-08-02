<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomStatusHistory;
use Modules\Operations\Housekeeping\Services\ChecklistService;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Modules\Operations\Housekeeping\Services\InspectionService;
use Modules\Operations\Housekeeping\Services\RoomService;
use Modules\Operations\Housekeeping\Services\TaskAssignmentService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class HousekeepingServiceTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ── Container resolution ──────────────────────────────────────────────────

    public function test_all_services_resolve_from_container(): void
    {
        $this->assertInstanceOf(RoomService::class,           app(RoomService::class));
        $this->assertInstanceOf(CleaningTaskService::class,   app(CleaningTaskService::class));
        $this->assertInstanceOf(TaskAssignmentService::class, app(TaskAssignmentService::class));
        $this->assertInstanceOf(InspectionService::class,     app(InspectionService::class));
        $this->assertInstanceOf(ChecklistService::class,      app(ChecklistService::class));
    }

    // ── RoomService::create ───────────────────────────────────────────────────

    public function test_create_room_fires_room_created_and_writes_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = app(RoomService::class)->create([
            'property_id' => $property->id,
            'room_number' => '101',
            'room_type'   => 'standard',
        ]);

        $this->assertInstanceOf(Room::class, $room);

        // RoomCreated → RecordRoomHistory → room_status_histories
        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'cleanliness',
            'action'       => 'room_created',
        ]);

        // RoomCreated → LogHousekeepingActivity → activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Room::class,
            'subject_id'   => $room->id,
        ]);
    }

    // ── RoomService::update strips status dimensions ──────────────────────────

    public function test_update_strips_cleanliness_and_occupancy_status(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '102', 'room_type' => 'standard']);

        // Attempt to change status via update — must be silently stripped
        $updated = $service->update($room->id, [
            'room_name'          => 'Pool View',
            'cleanliness_status' => 'clean',
            'occupancy_status'   => 'occupied',
        ]);

        $this->assertSame('Pool View', $updated->room_name);
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty, $updated->cleanliness_status); // unchanged
        $this->assertNull($updated->occupancy_status);                                      // unchanged
    }

    // ── RoomService::changeCleanlinessStatus ──────────────────────────────────

    public function test_valid_cleanliness_transition_succeeds(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '201', 'room_type' => 'standard']);

        $updated = $service->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Clean, 'Cleaned by Maria');

        $this->assertSame(RoomCleanlinessStatusEnum::Clean, $updated->cleanliness_status);

        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'cleanliness',
            'from_status'  => 'dirty',
            'to_status'    => 'clean',
            'action'       => 'room_cleaned',
            'remarks'      => 'Cleaned by Maria',
        ]);
    }

    public function test_invalid_cleanliness_transition_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '202', 'room_type' => 'standard']);

        // dirty → inspected is prohibited
        $this->expectException(ValidationException::class);
        $service->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Inspected);
    }

    // ── RoomService::changeOccupancyStatus (null → vacant) ───────────────────

    public function test_occupancy_transition_from_null_to_vacant_succeeds(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '301', 'room_type' => 'villa']);

        $this->assertNull($room->occupancy_status);

        $updated = $service->changeOccupancyStatus($room->id, RoomOccupancyStatusEnum::Vacant);

        $this->assertSame(RoomOccupancyStatusEnum::Vacant, $updated->occupancy_status);

        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'occupancy',
            'from_status'  => null,
            'to_status'    => 'vacant',
            'action'       => 'room_vacated',
        ]);
    }

    public function test_invalid_occupancy_transition_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '302', 'room_type' => 'standard']);
        $service->changeOccupancyStatus($room->id, RoomOccupancyStatusEnum::Occupied);

        // occupied → blocked is prohibited
        $this->expectException(ValidationException::class);
        $service->changeOccupancyStatus($room->id, RoomOccupancyStatusEnum::Blocked);
    }

    // ── CleaningTaskService::create ───────────────────────────────────────────

    public function test_create_task_fires_event_and_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task = app(CleaningTaskService::class)->create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-001',
            'title'       => 'Checkout Clean Room 101',
            'task_type'   => TaskTypeEnum::CheckoutCleaning->value,
            'priority'    => 3,
        ]);

        $this->assertSame(TaskStatusEnum::Pending, $task->status);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => \Modules\Operations\Housekeeping\Models\CleaningTask::class,
            'subject_id'   => $task->id,
        ]);
    }

    // ── CleaningTaskService::changeStatus → in_progress ──────────────────────

    public function test_task_in_progress_sets_started_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);

        $task = $service->create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-002',
            'title'       => 'Deep Clean',
            'task_type'   => TaskTypeEnum::DeepCleaning->value,
            'priority'    => 2,
        ]);

        $assignment = $service->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $this->assertSame(AssignmentStatusEnum::Active, $assignment->status);

        $started = $service->changeStatus($task->id, TaskStatusEnum::InProgress, $admin->id);

        $this->assertNotNull($started->started_at);
        $this->assertSame(TaskStatusEnum::InProgress, $started->status);
    }

    // ── CleaningTaskService::changeStatus → completed ─────────────────────────

    public function test_task_completed_sets_completed_at_and_completed_by(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);
        $room = app(RoomService::class)->create([
            'property_id' => $property->id,
            'room_number' => '603',
            'room_type'   => 'standard',
        ]);

        $task = $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'task_code'   => 'TSK-003',
            'title'       => 'Turndown',
            'task_type'   => TaskTypeEnum::Turndown->value,
            'priority'    => 3,
        ]);

        $assignment = $service->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $this->assertSame(AssignmentStatusEnum::Active, $assignment->status);

        $service->changeStatus($task->id, TaskStatusEnum::InProgress, $admin->id);
        $completed = $service->changeStatus(
            $task->id,
            TaskStatusEnum::Completed,
            $admin->id,
            'Task completed and ready for inspection.'
        );

        $this->assertNotNull($completed->completed_at);
        $this->assertSame($admin->id, $completed->completed_by);
        $this->assertSame(TaskStatusEnum::Completed, $completed->status);
        $this->assertSame(RoomCleanlinessStatusEnum::Clean, $room->refresh()->cleanliness_status);
        $this->assertSame('waiting_inspection', $room->readiness_state);
        $this->assertDatabaseHas('room_inspections', [
            'property_id'       => $property->id,
            'room_id'           => $room->id,
            'cleaning_task_id'  => $task->id,
            'status'            => 'pending',
            'inspection_type'   => 'post_cleaning',
        ]);
    }

    // ── CleaningTaskService::changeStatus → invalid transition ───────────────

    public function test_invalid_task_status_transition_throws(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);

        $task = $service->create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-004',
            'title'       => 'Skip Test',
            'task_type'   => TaskTypeEnum::Custom->value,
            'priority'    => 3,
        ]);

        // pending → in_progress skips assigned step — prohibited
        $this->expectException(ValidationException::class);
        $service->changeStatus($task->id, TaskStatusEnum::InProgress);
    }

    // ── CleaningTaskService::assign ───────────────────────────────────────────

    public function test_assign_creates_assignment_and_transitions_task_to_assigned(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(CleaningTaskService::class);

        $task = $service->create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-005',
            'title'       => 'Assign Test',
            'task_type'   => TaskTypeEnum::CheckoutCleaning->value,
            'priority'    => 3,
        ]);

        $assignment = $service->assign($task->id, [
            'user_id'       => $admin->id,
            'department_id' => $department->id,
        ]);

        $this->assertSame(AssignmentStatusEnum::Active, $assignment->status);
        $this->assertDatabaseHas('housekeeping_task_assignments', [
            'cleaning_task_id' => $task->id,
            'user_id'          => $admin->id,
        ]);

        $this->assertSame(TaskStatusEnum::Assigned, $task->refresh()->status);
    }

    // ── InspectionService::pass → room becomes inspected ─────────────────────

    public function test_inspection_pass_transitions_room_to_inspected(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $roomService       = app(RoomService::class);
        $inspectionService = app(InspectionService::class);

        // Create room and clean it (dirty → clean)
        $room = $roomService->create(['property_id' => $property->id, 'room_number' => '601', 'room_type' => 'standard']);
        $roomService->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Clean);

        // Create and pass inspection
        $inspection = $inspectionService->create([
            'property_id'     => $property->id,
            'room_id'         => $room->id,
            'inspection_type' => InspectionTypeEnum::PostCleaning->value,
        ]);

        $passed = $inspectionService->pass($inspection->id, 'All items checked');

        $this->assertSame(InspectionStatusEnum::Passed, $passed->status);
        $this->assertNotNull($passed->inspected_at);

        $room->refresh();
        $this->assertSame(RoomCleanlinessStatusEnum::Inspected, $room->cleanliness_status);
    }

    // ── InspectionService::fail → room becomes dirty ──────────────────────────

    public function test_inspection_fail_transitions_room_to_dirty(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $roomService       = app(RoomService::class);
        $inspectionService = app(InspectionService::class);

        // Clean the room first
        $room = $roomService->create(['property_id' => $property->id, 'room_number' => '602', 'room_type' => 'standard']);
        $roomService->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Clean);

        $inspection = $inspectionService->create([
            'property_id'     => $property->id,
            'room_id'         => $room->id,
            'inspection_type' => InspectionTypeEnum::PostCleaning->value,
        ]);

        $failed = $inspectionService->fail($inspection->id, 'Bathroom not clean', InspectionSeverityEnum::Major);

        $this->assertSame(InspectionStatusEnum::Failed, $failed->status);
        $this->assertSame(InspectionSeverityEnum::Major, $failed->inspection_severity);
        $this->assertNotNull($failed->inspected_at);

        $room->refresh();
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty, $room->cleanliness_status);
    }

    // ── TaskAssignmentService ─────────────────────────────────────────────────

    public function test_assignment_service_complete_sets_completed_at(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $taskService       = app(CleaningTaskService::class);
        $assignmentService = app(TaskAssignmentService::class);

        $task       = $taskService->create(['property_id' => $property->id, 'task_code' => 'ASN-001', 'title' => 'Test', 'task_type' => 'custom', 'priority' => 3]);
        $assignment = $taskService->assign($task->id, ['user_id' => $admin->id, 'department_id' => $department->id]);

        $completed = $assignmentService->complete($assignment->id);
        $this->assertSame(AssignmentStatusEnum::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    // ── ChecklistService ──────────────────────────────────────────────────────

    public function test_checklist_service_add_and_reorder_items(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Test Checklist', 'is_active' => true]);

        $item1 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step A', 'sort_order' => 0]);
        $item2 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step B', 'sort_order' => 1]);

        $service->reorderItems([$item2->id, $item1->id]);

        $updated = $service->find($checklist->id);
        $this->assertSame('Step B', $updated->items->first()->item_text);
        $this->assertSame('Step A', $updated->items->last()->item_text);
    }
}

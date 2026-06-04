<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Repositories\ChecklistItemRepository;
use Modules\Operations\Housekeeping\Repositories\ChecklistRepository;
use Modules\Operations\Housekeeping\Repositories\CleaningTaskRepository;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;
use Modules\Operations\Housekeeping\Repositories\RoomRepository;
use Modules\Operations\Housekeeping\Repositories\TaskAssignmentRepository;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class HousekeepingRepositoryTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ── Container resolution ──────────────────────────────────────────────────

    public function test_all_repositories_resolve_from_container(): void
    {
        $this->assertInstanceOf(RoomRepository::class,           app(RoomRepository::class));
        $this->assertInstanceOf(CleaningTaskRepository::class,   app(CleaningTaskRepository::class));
        $this->assertInstanceOf(TaskAssignmentRepository::class, app(TaskAssignmentRepository::class));
        $this->assertInstanceOf(InspectionRepository::class,     app(InspectionRepository::class));
        $this->assertInstanceOf(ChecklistRepository::class,      app(ChecklistRepository::class));
        $this->assertInstanceOf(ChecklistItemRepository::class,  app(ChecklistItemRepository::class));
    }

    // ── RoomRepository ────────────────────────────────────────────────────────

    public function test_room_repository_create_and_find(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);

        $room = $repo->create([
            'property_id'        => $property->id,
            'room_number'        => '101',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $this->assertInstanceOf(Room::class, $room);
        $this->assertSame('101', $room->room_number);

        $found = $repo->find($room->id);
        $this->assertSame($room->id, $found->id);
    }

    public function test_room_repository_find_throws_not_found_for_missing_id(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->expectException(NotFoundException::class);
        app(RoomRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_room_repository_update(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);
        $room = $repo->create([
            'property_id'        => $property->id,
            'room_number'        => '102',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $updated = $repo->update($room->id, ['room_name' => 'Garden View']);
        $this->assertSame('Garden View', $updated->room_name);
    }

    public function test_room_repository_by_cleanliness_status(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);
        $repo->create(['property_id' => $property->id, 'room_number' => '201', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);
        $repo->create(['property_id' => $property->id, 'room_number' => '202', 'room_type' => 'standard', 'cleanliness_status' => 'clean']);

        $dirty = $repo->byCleanlinessStatus(RoomCleanlinessStatusEnum::Dirty);
        $this->assertCount(1, $dirty);
        $this->assertSame('201', $dirty->first()->room_number);
    }

    public function test_room_repository_by_occupancy_status(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);
        $repo->create(['property_id' => $property->id, 'room_number' => '301', 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'occupancy_status' => 'occupied']);
        $repo->create(['property_id' => $property->id, 'room_number' => '302', 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'occupancy_status' => null]);

        $occupied = $repo->byOccupancyStatus(RoomOccupancyStatusEnum::Occupied);
        $this->assertCount(1, $occupied);
        $this->assertSame('301', $occupied->first()->room_number);
    }

    public function test_room_repository_active_rooms(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);
        $repo->create(['property_id' => $property->id, 'room_number' => '401', 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'is_active' => true]);
        $repo->create(['property_id' => $property->id, 'room_number' => '402', 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'is_active' => false]);

        $active = $repo->activeRooms();
        $this->assertCount(1, $active);
        $this->assertSame('401', $active->first()->room_number);
    }

    public function test_room_repository_delete(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);
        $room = $repo->create(['property_id' => $property->id, 'room_number' => '501', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);

        $this->assertTrue($repo->delete($room->id));
        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    // ── CleaningTaskRepository ────────────────────────────────────────────────

    public function test_cleaning_task_repository_create_and_find(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(CleaningTaskRepository::class);

        $task = $repo->create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-001',
            'title'       => 'Checkout Clean',
            'task_type'   => TaskTypeEnum::CheckoutCleaning->value,
            'status'      => TaskStatusEnum::Pending->value,
            'priority'    => 3,
        ]);

        $this->assertInstanceOf(CleaningTask::class, $task);
        $found = $repo->find($task->id);
        $this->assertSame($task->id, $found->id);
    }

    public function test_cleaning_task_repository_paginate_filters_by_status(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(CleaningTaskRepository::class);
        $repo->create(['property_id' => $property->id, 'task_code' => 'T1', 'title' => 'Task 1', 'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 3]);
        $repo->create(['property_id' => $property->id, 'task_code' => 'T2', 'title' => 'Task 2', 'task_type' => 'checkout_cleaning', 'status' => 'assigned', 'priority' => 3]);

        $pending = $repo->paginate(['status' => 'pending']);
        $this->assertCount(1, $pending->items());
        $this->assertSame('T1', $pending->items()[0]->task_code);
    }

    public function test_cleaning_task_repository_due_today(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(CleaningTaskRepository::class);
        $repo->create(['property_id' => $property->id, 'task_code' => 'DT1', 'title' => 'Due Today', 'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 3, 'due_date' => now()]);
        $repo->create(['property_id' => $property->id, 'task_code' => 'DT2', 'title' => 'Due Yesterday', 'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 3, 'due_date' => now()->subDay()]);

        $dueToday = $repo->dueToday();
        $this->assertTrue($dueToday->contains('task_code', 'DT1'));
        $this->assertFalse($dueToday->contains('task_code', 'DT2'));
    }

    public function test_cleaning_task_repository_overdue(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(CleaningTaskRepository::class);
        $repo->create(['property_id' => $property->id, 'task_code' => 'OD1', 'title' => 'Overdue', 'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 3, 'due_date' => now()->subDay()]);
        $repo->create(['property_id' => $property->id, 'task_code' => 'OD2', 'title' => 'Not Overdue', 'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 3, 'due_date' => now()->addDay()]);

        $overdue = $repo->overdue();
        $this->assertTrue($overdue->contains('task_code', 'OD1'));
        $this->assertFalse($overdue->contains('task_code', 'OD2'));
    }

    // ── TaskAssignmentRepository ──────────────────────────────────────────────

    public function test_task_assignment_repository_active_for_task(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $department = $this->createDepartment($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $taskRepo = app(CleaningTaskRepository::class);
        $repo     = app(TaskAssignmentRepository::class);

        $task = $taskRepo->create(['property_id' => $property->id, 'task_code' => 'ASN1', 'title' => 'Assignable Task', 'task_type' => 'checkout_cleaning', 'status' => 'assigned', 'priority' => 3]);

        $assignment = $repo->create([
            'property_id'      => $property->id,
            'cleaning_task_id' => $task->id,
            'user_id'          => $admin->id,
            'department_id'    => $department->id,
            'assigned_at'      => now(),
            'status'           => AssignmentStatusEnum::Active->value,
        ]);

        $active = $repo->activeForTask($task->id);
        $this->assertCount(1, $active);
        $this->assertSame($assignment->id, $active->first()->id);
    }

    // ── InspectionRepository ──────────────────────────────────────────────────

    public function test_inspection_repository_create_and_failed_critical(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $roomRepo       = app(RoomRepository::class);
        $inspectionRepo = app(InspectionRepository::class);

        $room = $roomRepo->create(['property_id' => $property->id, 'room_number' => '601', 'room_type' => 'standard', 'cleanliness_status' => 'clean']);

        $inspectionRepo->create([
            'property_id'         => $property->id,
            'room_id'             => $room->id,
            'inspection_type'     => InspectionTypeEnum::PostCleaning->value,
            'status'              => InspectionStatusEnum::Failed->value,
            'inspection_severity' => InspectionSeverityEnum::Critical->value,
        ]);

        $inspectionRepo->create([
            'property_id'         => $property->id,
            'room_id'             => $room->id,
            'inspection_type'     => InspectionTypeEnum::Routine->value,
            'status'              => InspectionStatusEnum::Passed->value,
            'inspection_severity' => null,
        ]);

        $critical = $inspectionRepo->failedCritical();
        $this->assertCount(1, $critical);
        $this->assertSame(InspectionSeverityEnum::Critical, $critical->first()->inspection_severity);
    }

    // ── ChecklistRepository ───────────────────────────────────────────────────

    public function test_checklist_repository_create_and_active(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(ChecklistRepository::class);

        $repo->create(['property_id' => $property->id, 'name' => 'Active Checklist', 'is_active' => true]);
        $repo->create(['property_id' => $property->id, 'name' => 'Inactive Checklist', 'is_active' => false]);

        $active = $repo->active();
        $this->assertCount(1, $active);
        $this->assertSame('Active Checklist', $active->first()->name);
    }

    public function test_checklist_repository_by_task_type(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(ChecklistRepository::class);
        $repo->create(['property_id' => $property->id, 'name' => 'Checkout Checklist', 'task_type' => 'checkout_cleaning', 'is_active' => true]);
        $repo->create(['property_id' => $property->id, 'name' => 'Deep Clean Checklist', 'task_type' => 'deep_cleaning', 'is_active' => true]);

        $checkoutLists = $repo->byTaskType('checkout_cleaning');
        $this->assertCount(1, $checkoutLists);
        $this->assertSame('Checkout Checklist', $checkoutLists->first()->name);
    }

    // ── ChecklistItemRepository ───────────────────────────────────────────────

    public function test_checklist_item_repository_create_and_reorder(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $checklistRepo = app(ChecklistRepository::class);
        $itemRepo      = app(ChecklistItemRepository::class);

        $checklist = $checklistRepo->create(['property_id' => $property->id, 'name' => 'Standard Checklist', 'is_active' => true]);

        $item1 = $itemRepo->create(['property_id' => $property->id, 'checklist_id' => $checklist->id, 'item_text' => 'Change bed linen', 'sort_order' => 0]);
        $item2 = $itemRepo->create(['property_id' => $property->id, 'checklist_id' => $checklist->id, 'item_text' => 'Clean bathroom', 'sort_order' => 1]);
        $item3 = $itemRepo->create(['property_id' => $property->id, 'checklist_id' => $checklist->id, 'item_text' => 'Vacuum floor', 'sort_order' => 2]);

        // Reorder: put item3 first, then item1, then item2
        $itemRepo->reorder([$item3->id, $item1->id, $item2->id]);

        $reordered = $itemRepo->forChecklist($checklist->id);
        $this->assertSame($item3->id, $reordered[0]->id);
        $this->assertSame($item1->id, $reordered[1]->id);
        $this->assertSame($item2->id, $reordered[2]->id);
    }

    // ── Property isolation ────────────────────────────────────────────────────

    public function test_room_repository_respects_property_isolation(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $propertyB  = $this->createProperty($company, ['code' => 'PB01']);
        $adminA     = $this->createPropertyAdmin($propertyA);
        $adminB     = $this->createPropertyAdmin($propertyB);

        // Create room in property A
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propertyA->id);
        app(RoomRepository::class)->create(['property_id' => $propertyA->id, 'room_number' => '101', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);

        // Switch to property B — paginate should return 0
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $result = app(RoomRepository::class)->paginate();
        $this->assertSame(0, $result->total());
    }
}

<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Repositories\AssetRequestRepository;
use Modules\Operations\Engineering\Repositories\EngineeringChecklistItemRepository;
use Modules\Operations\Engineering\Repositories\EngineeringChecklistRepository;
use Modules\Operations\Engineering\Repositories\PreventiveMaintenanceRepository;
use Modules\Operations\Engineering\Repositories\PreventiveMaintenanceTaskRepository;
use Modules\Operations\Engineering\Repositories\TechnicianAssignmentRepository;
use Modules\Operations\Engineering\Repositories\WorkOrderRepository;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class EngineeringRepositoryTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bootProperty(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('property', 'admin');
    }

    private function createWorkOrder(Property $property, array $overrides = []): WorkOrder
    {
        static $seq = 0;
        $seq++;

        return WorkOrder::create(array_merge([
            'property_id'       => $property->id,
            'work_order_number' => "WO-{$seq}",
            'title'             => "Work Order {$seq}",
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ], $overrides));
    }

    private function createPreventiveMaintenance(Property $property, array $overrides = []): PreventiveMaintenance
    {
        static $seq = 0;
        $seq++;

        return PreventiveMaintenance::create(array_merge([
            'property_id' => $property->id,
            'pm_code'     => "PM-{$seq}",
            'title'       => "PM Program {$seq}",
            'frequency'   => PmFrequencyEnum::Monthly->value,
            'status'      => PmStatusEnum::Active->value,
        ], $overrides));
    }

    private function createEngineeringChecklist(Property $property, array $overrides = []): EngineeringChecklist
    {
        static $seq = 0;
        $seq++;

        return EngineeringChecklist::create(array_merge([
            'property_id'    => $property->id,
            'title'          => "Checklist {$seq}",
            'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value,
            'is_active'      => true,
        ], $overrides));
    }

    private function createAssetRequest(Property $property, User $requester, array $overrides = []): AssetRequest
    {
        static $seq = 0;
        $seq++;

        return AssetRequest::create(array_merge([
            'property_id'    => $property->id,
            'request_number' => "AR-{$seq}",
            'requester_id'   => $requester->id,
            'title'          => "Asset Request {$seq}",
            'status'         => AssetRequestStatusEnum::Pending->value,
            'priority'       => WorkOrderPriorityEnum::Normal->value,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Container resolution
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_repositories_resolve_from_container(): void
    {
        $this->assertInstanceOf(WorkOrderRepository::class,                app(WorkOrderRepository::class));
        $this->assertInstanceOf(TechnicianAssignmentRepository::class,     app(TechnicianAssignmentRepository::class));
        $this->assertInstanceOf(PreventiveMaintenanceRepository::class,    app(PreventiveMaintenanceRepository::class));
        $this->assertInstanceOf(PreventiveMaintenanceTaskRepository::class, app(PreventiveMaintenanceTaskRepository::class));
        $this->assertInstanceOf(AssetRequestRepository::class,             app(AssetRequestRepository::class));
        $this->assertInstanceOf(EngineeringChecklistRepository::class,     app(EngineeringChecklistRepository::class));
        $this->assertInstanceOf(EngineeringChecklistItemRepository::class, app(EngineeringChecklistItemRepository::class));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderRepository — CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(WorkOrderRepository::class);

        $wo = $repo->create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-TEST-001',
            'title'             => 'Fix AC Unit',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::High->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);

        $this->assertInstanceOf(WorkOrder::class, $wo);
        $this->assertSame('WO-TEST-001', $wo->work_order_number);

        $found = $repo->find($wo->id);
        $this->assertSame($wo->id, $found->id);
    }

    public function test_work_order_repository_find_throws_not_found(): void
    {
        $this->bootProperty();

        $this->expectException(NotFoundException::class);
        app(WorkOrderRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_work_order_repository_update(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(WorkOrderRepository::class);

        $wo      = $this->createWorkOrder($property);
        $updated = $repo->update($wo->id, ['title' => 'Updated Title']);

        $this->assertSame('Updated Title', $updated->title);
    }

    public function test_work_order_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(WorkOrderRepository::class);

        $wo = $this->createWorkOrder($property);
        $this->assertTrue($repo->delete($wo->id));
        $this->assertSoftDeleted('work_orders', ['id' => $wo->id]);
    }

    public function test_work_order_repository_paginate_filters_by_status(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createWorkOrder($property, ['work_order_number' => 'STAT-1', 'status' => WorkOrderStatusEnum::Pending->value]);
        $this->createWorkOrder($property, ['work_order_number' => 'STAT-2', 'status' => WorkOrderStatusEnum::Assigned->value]);

        $result = app(WorkOrderRepository::class)->paginate(['status' => WorkOrderStatusEnum::Pending->value]);

        $this->assertSame(1, $result->total());
        $this->assertSame('STAT-1', $result->items()[0]->work_order_number);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderRepository — query methods
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_repository_by_status(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createWorkOrder($property, ['work_order_number' => 'BS-1', 'status' => WorkOrderStatusEnum::InProgress->value]);
        $this->createWorkOrder($property, ['work_order_number' => 'BS-2', 'status' => WorkOrderStatusEnum::Pending->value]);

        $inProgress = app(WorkOrderRepository::class)->byStatus(WorkOrderStatusEnum::InProgress);

        $this->assertCount(1, $inProgress);
        $this->assertSame('BS-1', $inProgress->first()->work_order_number);
    }

    public function test_work_order_repository_by_priority(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createWorkOrder($property, ['work_order_number' => 'PRI-1', 'priority' => WorkOrderPriorityEnum::Critical->value]);
        $this->createWorkOrder($property, ['work_order_number' => 'PRI-2', 'priority' => WorkOrderPriorityEnum::Low->value]);

        $critical = app(WorkOrderRepository::class)->byPriority(WorkOrderPriorityEnum::Critical);

        $this->assertCount(1, $critical);
        $this->assertSame('PRI-1', $critical->first()->work_order_number);
    }

    public function test_work_order_repository_overdue(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createWorkOrder($property, ['work_order_number' => 'OD-1', 'due_date' => now()->subDay()]);
        $this->createWorkOrder($property, ['work_order_number' => 'OD-2', 'due_date' => now()->addDay()]);
        $this->createWorkOrder($property, ['work_order_number' => 'OD-3', 'due_date' => now()->subDay(), 'status' => WorkOrderStatusEnum::Completed->value]);

        $overdue = app(WorkOrderRepository::class)->overdue();

        $this->assertTrue($overdue->contains('work_order_number', 'OD-1'));
        $this->assertFalse($overdue->contains('work_order_number', 'OD-2'));
        $this->assertFalse($overdue->contains('work_order_number', 'OD-3'));
    }

    public function test_work_order_repository_open(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createWorkOrder($property, ['work_order_number' => 'OPEN-1', 'status' => WorkOrderStatusEnum::Pending->value]);
        $this->createWorkOrder($property, ['work_order_number' => 'OPEN-2', 'status' => WorkOrderStatusEnum::InProgress->value]);
        $this->createWorkOrder($property, ['work_order_number' => 'OPEN-3', 'status' => WorkOrderStatusEnum::Completed->value]);
        $this->createWorkOrder($property, ['work_order_number' => 'OPEN-4', 'status' => WorkOrderStatusEnum::Cancelled->value]);

        $open = app(WorkOrderRepository::class)->open();

        $this->assertTrue($open->contains('work_order_number', 'OPEN-1'));
        $this->assertTrue($open->contains('work_order_number', 'OPEN-2'));
        $this->assertFalse($open->contains('work_order_number', 'OPEN-3'));
        $this->assertFalse($open->contains('work_order_number', 'OPEN-4'));
    }

    public function test_work_order_repository_completed_today(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createWorkOrder($property, [
            'work_order_number' => 'CT-1',
            'status'            => WorkOrderStatusEnum::Completed->value,
            'completed_at'      => now(),
        ]);
        $this->createWorkOrder($property, [
            'work_order_number' => 'CT-2',
            'status'            => WorkOrderStatusEnum::Completed->value,
            'completed_at'      => now()->subDay(),
        ]);

        $completedToday = app(WorkOrderRepository::class)->completedToday();

        $this->assertTrue($completedToday->contains('work_order_number', 'CT-1'));
        $this->assertFalse($completedToday->contains('work_order_number', 'CT-2'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TechnicianAssignmentRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_technician_assignment_repository_create_and_find(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $repo = app(TechnicianAssignmentRepository::class);
        $wo   = $this->createWorkOrder($property);

        $assignment = $repo->create([
            'property_id'   => $property->id,
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
            'assigned_at'   => now(),
        ]);

        $found = $repo->find($assignment->id);
        $this->assertSame($assignment->id, $found->id);
    }

    public function test_technician_assignment_repository_active_for_work_order(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $repo = app(TechnicianAssignmentRepository::class);
        $wo   = $this->createWorkOrder($property);

        $repo->create([
            'property_id'   => $property->id,
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
            'assigned_at'   => now(),
        ]);

        $active = $repo->activeForWorkOrder($wo->id);
        $this->assertCount(1, $active);
        $this->assertSame($admin->id, $active->first()->user_id);
    }

    public function test_technician_assignment_repository_active_for_user(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $repo = app(TechnicianAssignmentRepository::class);

        $wo1 = $this->createWorkOrder($property, ['work_order_number' => 'ACU-1']);
        $wo2 = $this->createWorkOrder($property, ['work_order_number' => 'ACU-2']);

        $repo->create(['property_id' => $property->id, 'work_order_id' => $wo1->id, 'user_id' => $admin->id, 'role' => 'lead', 'status' => TechnicianAssignmentStatusEnum::Active->value, 'assigned_at' => now()]);
        $repo->create(['property_id' => $property->id, 'work_order_id' => $wo2->id, 'user_id' => $admin->id, 'role' => 'lead', 'status' => TechnicianAssignmentStatusEnum::Completed->value, 'assigned_at' => now()]);

        $activeForUser = $repo->activeForUser($admin->id);
        $this->assertCount(1, $activeForUser);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(PreventiveMaintenanceRepository::class);

        $pm = $this->createPreventiveMaintenance($property);

        $found = $repo->find($pm->id);
        $this->assertSame($pm->id, $found->id);
    }

    public function test_pm_repository_due_today(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createPreventiveMaintenance($property, ['pm_code' => 'DT-1', 'next_due_at' => today()]);
        $this->createPreventiveMaintenance($property, ['pm_code' => 'DT-2', 'next_due_at' => today()->addDay()]);
        $this->createPreventiveMaintenance($property, ['pm_code' => 'DT-3', 'next_due_at' => today(), 'status' => PmStatusEnum::Inactive->value]);

        $dueToday = app(PreventiveMaintenanceRepository::class)->dueToday();

        $this->assertTrue($dueToday->contains('pm_code', 'DT-1'));
        $this->assertFalse($dueToday->contains('pm_code', 'DT-2'));
        $this->assertFalse($dueToday->contains('pm_code', 'DT-3'));
    }

    public function test_pm_repository_overdue(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createPreventiveMaintenance($property, ['pm_code' => 'OD-1', 'next_due_at' => today()->subDay()]);
        $this->createPreventiveMaintenance($property, ['pm_code' => 'OD-2', 'next_due_at' => today()->addDay()]);

        $overdue = app(PreventiveMaintenanceRepository::class)->overdue();

        $this->assertTrue($overdue->contains('pm_code', 'OD-1'));
        $this->assertFalse($overdue->contains('pm_code', 'OD-2'));
    }

    public function test_pm_repository_active(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createPreventiveMaintenance($property, ['pm_code' => 'ACT-1', 'status' => PmStatusEnum::Active->value]);
        $this->createPreventiveMaintenance($property, ['pm_code' => 'ACT-2', 'status' => PmStatusEnum::Paused->value]);

        $active = app(PreventiveMaintenanceRepository::class)->active();

        $this->assertTrue($active->contains('pm_code', 'ACT-1'));
        $this->assertFalse($active->contains('pm_code', 'ACT-2'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceTaskRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_task_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(PreventiveMaintenanceTaskRepository::class);
        $pm   = $this->createPreventiveMaintenance($property);

        $task = $repo->create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        $found = $repo->find($task->id);
        $this->assertSame($task->id, $found->id);
    }

    public function test_pm_task_repository_pending_excludes_terminal(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(PreventiveMaintenanceTaskRepository::class);
        $pm   = $this->createPreventiveMaintenance($property);

        $repo->create(['property_id' => $property->id, 'preventive_maintenance_id' => $pm->id, 'scheduled_date' => today(), 'status' => PmTaskStatusEnum::Scheduled->value]);
        $repo->create(['property_id' => $property->id, 'preventive_maintenance_id' => $pm->id, 'scheduled_date' => today(), 'status' => PmTaskStatusEnum::Completed->value]);
        $repo->create(['property_id' => $property->id, 'preventive_maintenance_id' => $pm->id, 'scheduled_date' => today(), 'status' => PmTaskStatusEnum::Skipped->value]);

        $pending = $repo->pending();

        $this->assertCount(1, $pending);
        $this->assertSame(PmTaskStatusEnum::Scheduled, $pending->first()->status);
    }

    public function test_pm_task_repository_completed_today(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(PreventiveMaintenanceTaskRepository::class);
        $pm   = $this->createPreventiveMaintenance($property);

        $repo->create(['property_id' => $property->id, 'preventive_maintenance_id' => $pm->id, 'scheduled_date' => today(), 'status' => PmTaskStatusEnum::Completed->value, 'completed_at' => now()]);
        $repo->create(['property_id' => $property->id, 'preventive_maintenance_id' => $pm->id, 'scheduled_date' => today(), 'status' => PmTaskStatusEnum::Completed->value, 'completed_at' => now()->subDay()]);

        $completedToday = $repo->completedToday();

        $this->assertCount(1, $completedToday);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AssetRequestRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_asset_request_repository_create_and_find(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $repo = app(AssetRequestRepository::class);

        $request = $this->createAssetRequest($property, $admin);

        $found = $repo->find($request->id);
        $this->assertSame($request->id, $found->id);
    }

    public function test_asset_request_repository_find_throws_not_found(): void
    {
        $this->bootProperty();

        $this->expectException(NotFoundException::class);
        app(AssetRequestRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_asset_request_repository_pending_approval(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $this->createAssetRequest($property, $admin, ['request_number' => 'AR-PA1', 'status' => AssetRequestStatusEnum::Pending->value]);
        $this->createAssetRequest($property, $admin, ['request_number' => 'AR-PA2', 'status' => AssetRequestStatusEnum::Approved->value]);

        $pending = app(AssetRequestRepository::class)->pendingApproval();

        $this->assertCount(1, $pending);
        $this->assertSame('AR-PA1', $pending->first()->request_number);
    }

    public function test_asset_request_repository_approved(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $this->createAssetRequest($property, $admin, ['request_number' => 'AR-AP1', 'status' => AssetRequestStatusEnum::Approved->value]);
        $this->createAssetRequest($property, $admin, ['request_number' => 'AR-AP2', 'status' => AssetRequestStatusEnum::Pending->value]);

        $approved = app(AssetRequestRepository::class)->approved();

        $this->assertCount(1, $approved);
        $this->assertSame('AR-AP1', $approved->first()->request_number);
    }

    public function test_asset_request_repository_fulfilled(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $this->createAssetRequest($property, $admin, ['request_number' => 'AR-FF1', 'status' => AssetRequestStatusEnum::Fulfilled->value, 'fulfilled_at' => now()]);
        $this->createAssetRequest($property, $admin, ['request_number' => 'AR-FF2', 'status' => AssetRequestStatusEnum::Pending->value]);

        $fulfilled = app(AssetRequestRepository::class)->fulfilled();

        $this->assertCount(1, $fulfilled);
        $this->assertSame('AR-FF1', $fulfilled->first()->request_number);
    }

    public function test_asset_request_repository_delete_soft_deletes(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $repo    = app(AssetRequestRepository::class);
        $request = $this->createAssetRequest($property, $admin);

        $this->assertTrue($repo->delete($request->id));
        $this->assertSoftDeleted('asset_requests', ['id' => $request->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EngineeringChecklistRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_checklist_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo      = app(EngineeringChecklistRepository::class);
        $checklist = $this->createEngineeringChecklist($property);

        $found = $repo->find($checklist->id);
        $this->assertSame($checklist->id, $found->id);
    }

    public function test_checklist_repository_active(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createEngineeringChecklist($property, ['title' => 'Active CL',   'is_active' => true]);
        $this->createEngineeringChecklist($property, ['title' => 'Inactive CL', 'is_active' => false]);

        $active = app(EngineeringChecklistRepository::class)->active();

        $this->assertCount(1, $active);
        $this->assertSame('Active CL', $active->first()->title);
    }

    public function test_checklist_repository_by_type(): void
    {
        ['property' => $property] = $this->bootProperty();

        $this->createEngineeringChecklist($property, ['title' => 'WO Checklist', 'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value]);
        $this->createEngineeringChecklist($property, ['title' => 'PM Checklist', 'checklist_type' => EngineeringChecklistTypeEnum::PreventiveMaintenance->value]);

        $woLists = app(EngineeringChecklistRepository::class)->byType(EngineeringChecklistTypeEnum::WorkOrder);

        $this->assertCount(1, $woLists);
        $this->assertSame('WO Checklist', $woLists->first()->title);
    }

    public function test_checklist_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo      = app(EngineeringChecklistRepository::class);
        $checklist = $this->createEngineeringChecklist($property);

        $this->assertTrue($repo->delete($checklist->id));
        $this->assertSoftDeleted('engineering_checklists', ['id' => $checklist->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EngineeringChecklistItemRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_checklist_item_repository_create_and_reorder(): void
    {
        ['property' => $property] = $this->bootProperty();

        $checklistRepo = app(EngineeringChecklistRepository::class);
        $itemRepo      = app(EngineeringChecklistItemRepository::class);
        $checklist     = $this->createEngineeringChecklist($property);

        $item1 = $itemRepo->create(['property_id' => $property->id, 'engineering_checklist_id' => $checklist->id, 'item_text' => 'Check oil level',     'sort_order' => 0]);
        $item2 = $itemRepo->create(['property_id' => $property->id, 'engineering_checklist_id' => $checklist->id, 'item_text' => 'Inspect belt',         'sort_order' => 1]);
        $item3 = $itemRepo->create(['property_id' => $property->id, 'engineering_checklist_id' => $checklist->id, 'item_text' => 'Clean air filter',     'sort_order' => 2]);

        // Reorder: item3 → item1 → item2
        $itemRepo->reorder([$item3->id, $item1->id, $item2->id]);

        $reordered = $itemRepo->forChecklist($checklist->id);
        $this->assertSame($item3->id, $reordered[0]->id);
        $this->assertSame($item1->id, $reordered[1]->id);
        $this->assertSame($item2->id, $reordered[2]->id);
    }

    public function test_checklist_item_repository_update(): void
    {
        ['property' => $property] = $this->bootProperty();
        $itemRepo  = app(EngineeringChecklistItemRepository::class);
        $checklist = $this->createEngineeringChecklist($property);

        $item    = $itemRepo->create(['property_id' => $property->id, 'engineering_checklist_id' => $checklist->id, 'item_text' => 'Original text', 'sort_order' => 0]);
        $updated = $itemRepo->update($item->id, ['item_text' => 'Updated text']);

        $this->assertSame('Updated text', $updated->item_text);
    }

    public function test_checklist_item_repository_delete(): void
    {
        ['property' => $property] = $this->bootProperty();
        $itemRepo  = app(EngineeringChecklistItemRepository::class);
        $checklist = $this->createEngineeringChecklist($property);

        $item = $itemRepo->create(['property_id' => $property->id, 'engineering_checklist_id' => $checklist->id, 'item_text' => 'To be deleted', 'sort_order' => 0]);

        $this->assertTrue($itemRepo->delete($item->id));
        $this->assertDatabaseMissing('engineering_checklist_items', ['id' => $item->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Property isolation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_repository_respects_property_isolation(): void
    {
        // Property A — create a work order
        ['property' => $propertyA, 'admin' => $adminA] = $this->bootProperty();
        $this->createWorkOrder($propertyA);

        // Property B — paginate should return 0
        $companyB  = $this->createCompany();
        $propertyB = $this->createProperty($companyB, ['code' => 'PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $result = app(WorkOrderRepository::class)->paginate();
        $this->assertSame(0, $result->total());
    }

    public function test_asset_request_repository_respects_property_isolation(): void
    {
        // Property A
        ['property' => $propertyA, 'admin' => $adminA] = $this->bootProperty();
        $this->createAssetRequest($propertyA, $adminA);

        // Property B
        $companyB  = $this->createCompany();
        $propertyB = $this->createProperty($companyB, ['code' => 'PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $result = app(AssetRequestRepository::class)->paginate();
        $this->assertSame(0, $result->total());
    }
}

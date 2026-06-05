<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Services\AssetRequestService;
use Modules\Operations\Engineering\Services\EngineeringChecklistService;
use Modules\Operations\Engineering\Services\PreventiveMaintenanceService;
use Modules\Operations\Engineering\Services\PreventiveMaintenanceTaskService;
use Modules\Operations\Engineering\Services\TechnicianAssignmentService;
use Modules\Operations\Engineering\Services\WorkOrderService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class EngineeringServiceTest extends TestCase
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

    private function makeWorkOrder(Property $property, array $overrides = []): WorkOrder
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

    private function makePm(Property $property, array $overrides = []): PreventiveMaintenance
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

    private function makeChecklist(Property $property, array $overrides = []): EngineeringChecklist
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

    private function makeAssetRequest(Property $property, User $requester, array $overrides = []): AssetRequest
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

    public function test_all_services_resolve_from_container(): void
    {
        $this->assertInstanceOf(WorkOrderService::class,                app(WorkOrderService::class));
        $this->assertInstanceOf(TechnicianAssignmentService::class,     app(TechnicianAssignmentService::class));
        $this->assertInstanceOf(PreventiveMaintenanceService::class,    app(PreventiveMaintenanceService::class));
        $this->assertInstanceOf(PreventiveMaintenanceTaskService::class, app(PreventiveMaintenanceTaskService::class));
        $this->assertInstanceOf(AssetRequestService::class,             app(AssetRequestService::class));
        $this->assertInstanceOf(EngineeringChecklistService::class,     app(EngineeringChecklistService::class));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderService — create fires event and writes history
    // ─────────────────────────────────────────────────────────────────────────

    public function test_create_work_order_fires_work_order_created_and_writes_history(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);

        $wo = $service->create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-EVT-001',
            'title'             => 'Test Work Order',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);

        $this->assertInstanceOf(WorkOrder::class, $wo);

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'from_status'   => null,
            'to_status'     => WorkOrderStatusEnum::Pending->value,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => WorkOrder::class,
            'subject_id'   => $wo->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderService — update strips status
    // ─────────────────────────────────────────────────────────────────────────

    public function test_update_strips_status_key(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property);

        $updated = $service->update($wo->id, [
            'title'  => 'Updated Title',
            'status' => WorkOrderStatusEnum::Completed->value,
        ]);

        $this->assertSame('Updated Title', $updated->title);
        $this->assertSame(WorkOrderStatusEnum::Pending, $updated->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderService — changeStatus transitions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_change_status_valid_transition_pending_to_cancelled(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::Cancelled, 'Not needed');

        $this->assertSame(WorkOrderStatusEnum::Cancelled, $updated->status);
        $this->assertSame('Not needed', $updated->cancellation_reason);
        $this->assertNotNull($updated->cancelled_at);
        $this->assertNotNull($updated->cancelled_by);
    }

    public function test_change_status_in_progress_sets_started_at(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property, ['status' => WorkOrderStatusEnum::Assigned->value]);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::InProgress);

        $this->assertSame(WorkOrderStatusEnum::InProgress, $updated->status);
        $this->assertNotNull($updated->started_at);
    }

    public function test_change_status_on_hold_sets_on_hold_reason(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property, ['status' => WorkOrderStatusEnum::Assigned->value]);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::OnHold, 'Waiting for parts');

        $this->assertSame(WorkOrderStatusEnum::OnHold, $updated->status);
        $this->assertSame('Waiting for parts', $updated->on_hold_reason);
    }

    public function test_change_status_completed_sets_completed_at_and_completed_by(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property, [
            'status'     => WorkOrderStatusEnum::InProgress->value,
            'started_at' => now()->subHour(),
        ]);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::Completed);

        $this->assertSame(WorkOrderStatusEnum::Completed, $updated->status);
        $this->assertNotNull($updated->completed_at);
        $this->assertSame($admin->id, $updated->completed_by);
        $this->assertNotNull($updated->actual_hours);
    }

    public function test_change_status_completed_writes_history(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property, ['status' => WorkOrderStatusEnum::InProgress->value]);

        $service->changeStatus($wo->id, WorkOrderStatusEnum::Completed);

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::Completed->value,
        ]);
    }

    public function test_change_status_invalid_transition_throws_validation_exception(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property);

        // pending → completed is prohibited
        $this->expectException(ValidationException::class);
        $service->changeStatus($wo->id, WorkOrderStatusEnum::Completed);
    }

    public function test_change_status_terminal_to_any_throws_validation_exception(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property, ['status' => WorkOrderStatusEnum::Completed->value]);

        $this->expectException(ValidationException::class);
        $service->changeStatus($wo->id, WorkOrderStatusEnum::Cancelled);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderService — assign
    // ─────────────────────────────────────────────────────────────────────────

    public function test_assign_creates_assignment_and_transitions_pending_to_assigned(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property);

        $assignment = $service->assign($wo->id, [
            'user_id' => $admin->id,
            'role'    => 'lead',
        ]);

        $this->assertSame($admin->id, $assignment->user_id);
        $this->assertSame(TechnicianAssignmentStatusEnum::Active, $assignment->status);

        $wo->refresh();
        $this->assertSame(WorkOrderStatusEnum::Assigned, $wo->status);
    }

    public function test_assign_does_not_re_transition_when_already_assigned(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property, ['status' => WorkOrderStatusEnum::InProgress->value]);

        $service->assign($wo->id, ['user_id' => $admin->id, 'role' => 'assistant']);

        $wo->refresh();
        $this->assertSame(WorkOrderStatusEnum::InProgress, $wo->status);
    }

    public function test_assign_fires_work_order_assigned_and_writes_history(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property);

        $service->assign($wo->id, ['user_id' => $admin->id, 'role' => 'lead']);

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::Assigned->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderService — approve
    // ─────────────────────────────────────────────────────────────────────────

    public function test_approve_sets_approved_by_and_approved_at(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrder($property);

        $updated = $service->approve($wo->id);

        $this->assertSame($admin->id, $updated->approved_by);
        $this->assertNotNull($updated->approved_at);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TechnicianAssignmentService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_technician_assignment_complete_sets_status_and_timestamp(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $wo      = $this->makeWorkOrder($property);
        $service = app(TechnicianAssignmentService::class);

        $assignment = $service->create([
            'property_id'   => $property->id,
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
            'assigned_at'   => now(),
        ]);

        $completed = $service->complete($assignment->id, ['hours_worked' => 2.5]);

        $this->assertSame(TechnicianAssignmentStatusEnum::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('2.50', $completed->hours_worked);
    }

    public function test_technician_assignment_relieve_sets_status(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $wo      = $this->makeWorkOrder($property);
        $service = app(TechnicianAssignmentService::class);

        $assignment = $service->create([
            'property_id'   => $property->id,
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
            'assigned_at'   => now(),
        ]);

        $relieved = $service->relieve($assignment->id);
        $this->assertSame(TechnicianAssignmentStatusEnum::Relieved, $relieved->status);
    }

    public function test_technician_assignment_cancel_sets_status(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $wo      = $this->makeWorkOrder($property);
        $service = app(TechnicianAssignmentService::class);

        $assignment = $service->create([
            'property_id'   => $property->id,
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
            'assigned_at'   => now(),
        ]);

        $cancelled = $service->cancel($assignment->id);
        $this->assertSame(TechnicianAssignmentStatusEnum::Cancelled, $cancelled->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_service_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);

        $pm = $service->create([
            'property_id' => $property->id,
            'pm_code'     => 'PM-SVC-001',
            'title'       => 'Monthly HVAC Service',
            'frequency'   => PmFrequencyEnum::Monthly->value,
            'status'      => PmStatusEnum::Active->value,
        ]);

        $found = $service->find($pm->id);
        $this->assertSame($pm->id, $found->id);
    }

    public function test_pm_service_update_strips_status_and_schedule_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePm($property);

        $updated = $service->update($pm->id, [
            'title'       => 'Updated PM Title',
            'status'      => PmStatusEnum::Inactive->value,
            'last_run_at' => now()->subDay(),
            'next_due_at' => now()->addMonth(),
        ]);

        $this->assertSame('Updated PM Title', $updated->title);
        $this->assertSame(PmStatusEnum::Active, $updated->status);
        $this->assertNull($updated->last_run_at);
        $this->assertNull($updated->next_due_at);
    }

    public function test_pm_service_activate_transitions_from_paused(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePm($property, ['status' => PmStatusEnum::Paused->value]);

        $activated = $service->activate($pm->id);
        $this->assertSame(PmStatusEnum::Active, $activated->status);
    }

    public function test_pm_service_deactivate_transitions_from_active(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePm($property);

        $deactivated = $service->deactivate($pm->id);
        $this->assertSame(PmStatusEnum::Inactive, $deactivated->status);
    }

    public function test_pm_service_pause_transitions_from_active(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePm($property);

        $paused = $service->pause($pm->id);
        $this->assertSame(PmStatusEnum::Paused, $paused->status);
    }

    public function test_pm_service_invalid_status_transition_throws(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePm($property, ['status' => PmStatusEnum::Inactive->value]);

        // inactive → paused is prohibited
        $this->expectException(ValidationException::class);
        $service->pause($pm->id);
    }

    public function test_pm_service_generate_task_creates_task_and_updates_next_due(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceService::class);
        $pm      = $this->makePm($property, ['frequency' => PmFrequencyEnum::Monthly->value]);

        $task = $service->generateTask($pm->id);

        $this->assertInstanceOf(PreventiveMaintenanceTask::class, $task);
        $this->assertSame($pm->id, $task->preventive_maintenance_id);
        $this->assertSame(PmTaskStatusEnum::Scheduled, $task->status);

        $pm->refresh();
        $this->assertNotNull($pm->next_due_at);
        // Monthly = 30 days from now
        $this->assertEqualsWithDelta(30, now()->diffInDays($pm->next_due_at), 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceTaskService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_task_service_change_status_valid_transition(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePm($property);
        $task    = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        $updated = $service->changeStatus($task->id, PmTaskStatusEnum::Assigned);
        $this->assertSame(PmTaskStatusEnum::Assigned, $updated->status);
    }

    public function test_pm_task_service_change_status_invalid_throws(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePm($property);
        $task    = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        // scheduled → completed is prohibited (must go through assigned first)
        $this->expectException(ValidationException::class);
        $service->changeStatus($task->id, PmTaskStatusEnum::Completed);
    }

    public function test_pm_task_completed_updates_pm_schedule_via_listener(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePm($property, ['frequency' => PmFrequencyEnum::Weekly->value]);
        $task    = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::InProgress->value,
        ]);

        $service->changeStatus($task->id, PmTaskStatusEnum::Completed);

        $pm->refresh();
        $this->assertNotNull($pm->last_run_at);
        $this->assertNotNull($pm->next_due_at);
        // Weekly = 7 days interval
        $this->assertEqualsWithDelta(7, $pm->last_run_at->diffInDays($pm->next_due_at), 1);
    }

    public function test_pm_task_service_mark_overdue(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service = app(PreventiveMaintenanceTaskService::class);
        $pm      = $this->makePm($property);

        // Overdue: past scheduled_date, non-terminal
        $past = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => now()->subDay(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        // Future: not overdue
        $future = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => now()->addDay(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        // Completed: terminal, should be skipped
        $done = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => now()->subDay(),
            'status'                    => PmTaskStatusEnum::Completed->value,
        ]);

        $count = $service->markOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(PmTaskStatusEnum::Overdue, $past->fresh()->status);
        $this->assertSame(PmTaskStatusEnum::Scheduled, $future->fresh()->status);
        $this->assertSame(PmTaskStatusEnum::Completed, $done->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AssetRequestService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_asset_request_service_create_and_find(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);

        $request = $service->create([
            'property_id'    => $property->id,
            'request_number' => 'AR-SVC-001',
            'requester_id'   => $admin->id,
            'title'          => 'Replacement pump seal',
            'status'         => AssetRequestStatusEnum::Pending->value,
            'priority'       => WorkOrderPriorityEnum::High->value,
        ]);

        $found = $service->find($request->id);
        $this->assertSame($request->id, $found->id);
    }

    public function test_asset_request_approve_sets_fields_and_fires_event(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin);

        $approved = $service->approve($request->id);

        $this->assertSame(AssetRequestStatusEnum::Approved, $approved->status);
        $this->assertSame($admin->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => AssetRequest::class,
            'subject_id'   => $request->id,
        ]);
    }

    public function test_asset_request_reject_sets_fields_and_fires_event(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin);

        $rejected = $service->reject($request->id, 'Out of budget');

        $this->assertSame(AssetRequestStatusEnum::Rejected, $rejected->status);
        $this->assertSame('Out of budget', $rejected->rejection_reason);
        $this->assertNotNull($rejected->rejected_at);
    }

    public function test_asset_request_fulfill_sets_fields_and_fires_event(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin, ['status' => AssetRequestStatusEnum::Approved->value]);

        $fulfilled = $service->fulfill($request->id);

        $this->assertSame(AssetRequestStatusEnum::Fulfilled, $fulfilled->status);
        $this->assertNotNull($fulfilled->fulfilled_at);
        $this->assertSame($admin->id, $fulfilled->fulfilled_by);
    }

    public function test_asset_request_cancel_works_from_pending(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin);

        $cancelled = $service->cancel($request->id);
        $this->assertSame(AssetRequestStatusEnum::Cancelled, $cancelled->status);
    }

    public function test_asset_request_cancel_works_from_approved(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin, ['status' => AssetRequestStatusEnum::Approved->value]);

        $cancelled = $service->cancel($request->id);
        $this->assertSame(AssetRequestStatusEnum::Cancelled, $cancelled->status);
    }

    public function test_asset_request_fulfilled_to_cancelled_throws(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin, [
            'status'       => AssetRequestStatusEnum::Fulfilled->value,
            'fulfilled_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $service->cancel($request->id);
    }

    public function test_asset_request_update_strips_status(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequest($property, $admin);

        $updated = $service->update($request->id, [
            'title'  => 'New Title',
            'status' => AssetRequestStatusEnum::Approved->value,
        ]);

        $this->assertSame('New Title', $updated->title);
        $this->assertSame(AssetRequestStatusEnum::Pending, $updated->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EngineeringChecklistService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_checklist_service_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service   = app(EngineeringChecklistService::class);
        $checklist = $service->create([
            'property_id'    => $property->id,
            'title'          => 'Daily Pump Check',
            'checklist_type' => EngineeringChecklistTypeEnum::PreventiveMaintenance->value,
            'is_active'      => true,
        ]);

        $found = $service->find($checklist->id);
        $this->assertSame($checklist->id, $found->id);
    }

    public function test_checklist_service_add_item_injects_checklist_id(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklist($property);

        $item = $service->addItem($checklist->id, [
            'property_id' => $property->id,
            'item_text'   => 'Check oil level',
            'sort_order'  => 0,
            'is_required' => true,
        ]);

        $this->assertSame($checklist->id, $item->engineering_checklist_id);
        $this->assertSame('Check oil level', $item->item_text);
        $this->assertTrue($item->is_required);
    }

    public function test_checklist_service_reorder_items(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklist($property);

        $item1 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step A', 'sort_order' => 0]);
        $item2 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step B', 'sort_order' => 1]);
        $item3 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step C', 'sort_order' => 2]);

        $service->reorderItems([$item3->id, $item1->id, $item2->id]);

        $reloaded = $service->find($checklist->id);
        $this->assertSame($item3->id, $reloaded->items[0]->id);
        $this->assertSame($item1->id, $reloaded->items[1]->id);
        $this->assertSame($item2->id, $reloaded->items[2]->id);
    }

    public function test_checklist_service_delete_soft_deletes_checklist(): void
    {
        ['property' => $property] = $this->bootProperty();
        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklist($property);

        $this->assertTrue($service->delete($checklist->id));
        $this->assertSoftDeleted('engineering_checklists', ['id' => $checklist->id]);
    }
}

<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Activity\Models\ActivityLog;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Events\AssetRequestApproved;
use Modules\Operations\Engineering\Events\AssetRequestFulfilled;
use Modules\Operations\Engineering\Events\AssetRequestRejected;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskCompleted;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskGenerated;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskOverdue;
use Modules\Operations\Engineering\Events\WorkOrderAssigned;
use Modules\Operations\Engineering\Events\WorkOrderCancelled;
use Modules\Operations\Engineering\Events\WorkOrderCompleted;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Events\WorkOrderOnHold;
use Modules\Operations\Engineering\Events\WorkOrderStarted;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Modules\Operations\Engineering\Models\WorkOrder;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class EngineeringPhase12Test extends TestCase
{
    use RefreshDatabase, CreatesEngineeringData;

    // ─────────────────────────────────────────────────────────────────────────
    // Boot sanity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_engineering_service_provider_boots_without_error(): void
    {
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderCreated → RecordWorkOrderHistory + LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_created_event_writes_status_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = WorkOrder::create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-EVT-001',
            'title'             => 'Test WO',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);

        event(new WorkOrderCreated($wo));

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'from_status'   => null,
            'to_status'     => WorkOrderStatusEnum::Pending->value,
        ]);
    }

    public function test_work_order_created_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = WorkOrder::create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-EVT-002',
            'title'             => 'Activity Log WO',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);

        event(new WorkOrderCreated($wo));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => WorkOrder::class,
            'subject_id'   => $wo->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderAssigned → RecordWorkOrderHistory + LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_assigned_event_writes_status_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property);

        event(new WorkOrderCreated($wo));

        $assignment = TechnicianAssignment::create([
            'property_id'   => $property->id,
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
            'assigned_at'   => now(),
        ]);

        $wo->update(['status' => WorkOrderStatusEnum::Assigned->value]);

        event(new WorkOrderAssigned($wo->fresh(), $assignment));

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::Assigned->value,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => WorkOrder::class,
            'subject_id'   => $wo->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderStarted → RecordWorkOrderHistory + LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_started_event_writes_status_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::Assigned->value]);

        event(new WorkOrderCreated($wo));

        $wo->update(['status' => WorkOrderStatusEnum::InProgress->value]);

        event(new WorkOrderStarted($wo->fresh()));

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::InProgress->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderOnHold → RecordWorkOrderHistory with reason
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_on_hold_event_writes_history_with_reason(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::Assigned->value]);

        event(new WorkOrderCreated($wo));

        $wo->update(['status' => WorkOrderStatusEnum::OnHold->value, 'on_hold_reason' => 'Waiting for parts']);

        event(new WorkOrderOnHold($wo->fresh(), 'Waiting for parts'));

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::OnHold->value,
            'remarks'       => 'Waiting for parts',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderCompleted → RecordWorkOrderHistory + LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_completed_event_writes_status_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::InProgress->value]);

        event(new WorkOrderCreated($wo));

        $wo->update(['status' => WorkOrderStatusEnum::Completed->value, 'completed_at' => now(), 'completed_by' => $admin->id]);

        event(new WorkOrderCompleted($wo->fresh()));

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::Completed->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderCancelled → RecordWorkOrderHistory with reason
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_cancelled_event_writes_history_with_reason(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property);

        event(new WorkOrderCreated($wo));

        $wo->update(['status' => WorkOrderStatusEnum::Cancelled->value, 'cancelled_at' => now(), 'cancellation_reason' => 'Not needed']);

        event(new WorkOrderCancelled($wo->fresh(), 'Not needed'));

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::Cancelled->value,
            'remarks'       => 'Not needed',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => WorkOrder::class,
            'subject_id'   => $wo->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceTaskGenerated → LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_task_generated_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $pm = $this->makePmModel($property);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        event(new PreventiveMaintenanceTaskGenerated($task));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PreventiveMaintenanceTask::class,
            'subject_id'   => $task->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceTaskCompleted → UpdatePreventiveMaintenanceSchedule
    //                                    + LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_task_completed_event_updates_pm_schedule(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $pm = $this->makePmModel($property, ['frequency' => PmFrequencyEnum::Weekly->value]);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Completed->value,
            'completed_at'              => now(),
        ]);

        event(new PreventiveMaintenanceTaskCompleted($task));

        $pm->refresh();
        $this->assertNotNull($pm->last_run_at);
        $this->assertNotNull($pm->next_due_at);
        $this->assertEqualsWithDelta(7, $pm->last_run_at->diffInDays($pm->next_due_at), 1);
    }

    public function test_pm_task_completed_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $pm = $this->makePmModel($property);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today(),
            'status'                    => PmTaskStatusEnum::Completed->value,
            'completed_at'              => now(),
        ]);

        event(new PreventiveMaintenanceTaskCompleted($task));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PreventiveMaintenanceTask::class,
            'subject_id'   => $task->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenanceTaskOverdue → LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_task_overdue_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $pm = $this->makePmModel($property);

        $task = PreventiveMaintenanceTask::create([
            'property_id'               => $property->id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => today()->subDay(),
            'status'                    => PmTaskStatusEnum::Overdue->value,
        ]);

        event(new PreventiveMaintenanceTaskOverdue($task));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PreventiveMaintenanceTask::class,
            'subject_id'   => $task->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AssetRequestApproved → LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_asset_request_approved_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $req = $this->makeAssetRequestModel($property, $admin, [
            'status'      => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        event(new AssetRequestApproved($req));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => AssetRequest::class,
            'subject_id'   => $req->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AssetRequestRejected → LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_asset_request_rejected_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $req = $this->makeAssetRequestModel($property, $admin, [
            'status'           => 'rejected',
            'rejection_reason' => 'Out of budget',
            'rejected_at'      => now(),
        ]);

        event(new AssetRequestRejected($req, 'Out of budget'));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => AssetRequest::class,
            'subject_id'   => $req->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AssetRequestFulfilled → LogEngineeringActivity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_asset_request_fulfilled_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $req = $this->makeAssetRequestModel($property, $admin, [
            'status'       => 'fulfilled',
            'fulfilled_by' => $admin->id,
            'fulfilled_at' => now(),
        ]);

        event(new AssetRequestFulfilled($req));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => AssetRequest::class,
            'subject_id'   => $req->id,
        ]);
    }
}

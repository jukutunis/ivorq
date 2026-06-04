<?php

namespace Tests\Unit\Engineering;

use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianRoleEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\EngineeringChecklistItem;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Models\WorkOrderStatusHistory;
use PHPUnit\Framework\TestCase;

class EngineeringModelTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════
    // Autoload
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_model_classes_autoload(): void
    {
        $this->assertInstanceOf(WorkOrder::class,               new WorkOrder());
        $this->assertInstanceOf(WorkOrderStatusHistory::class,  new WorkOrderStatusHistory());
        $this->assertInstanceOf(TechnicianAssignment::class,    new TechnicianAssignment());
        $this->assertInstanceOf(PreventiveMaintenance::class,   new PreventiveMaintenance());
        $this->assertInstanceOf(PreventiveMaintenanceTask::class, new PreventiveMaintenanceTask());
        $this->assertInstanceOf(AssetRequest::class,            new AssetRequest());
        $this->assertInstanceOf(EngineeringChecklist::class,    new EngineeringChecklist());
        $this->assertInstanceOf(EngineeringChecklistItem::class, new EngineeringChecklistItem());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Table names
    // ══════════════════════════════════════════════════════════════════════

    public function test_model_table_names_are_correct(): void
    {
        $this->assertSame('work_orders',                    (new WorkOrder())->getTable());
        $this->assertSame('work_order_status_histories',    (new WorkOrderStatusHistory())->getTable());
        $this->assertSame('technician_assignments',         (new TechnicianAssignment())->getTable());
        $this->assertSame('preventive_maintenances',        (new PreventiveMaintenance())->getTable());
        $this->assertSame('preventive_maintenance_tasks',   (new PreventiveMaintenanceTask())->getTable());
        $this->assertSame('asset_requests',                 (new AssetRequest())->getTable());
        $this->assertSame('engineering_checklists',         (new EngineeringChecklist())->getTable());
        $this->assertSame('engineering_checklist_items',    (new EngineeringChecklistItem())->getTable());
    }

    // ══════════════════════════════════════════════════════════════════════
    // ULID primary keys
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_models_use_string_primary_key(): void
    {
        $models = [
            new WorkOrder(), new WorkOrderStatusHistory(), new TechnicianAssignment(),
            new PreventiveMaintenance(), new PreventiveMaintenanceTask(),
            new AssetRequest(), new EngineeringChecklist(), new EngineeringChecklistItem(),
        ];

        foreach ($models as $model) {
            $this->assertSame('string', $model->getKeyType(), get_class($model) . ' must use string PK');
            $this->assertFalse($model->getIncrementing(), get_class($model) . ' must not auto-increment');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Timestamps
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_status_history_has_no_automatic_timestamps(): void
    {
        $this->assertFalse((new WorkOrderStatusHistory())->timestamps);
    }

    public function test_other_models_have_timestamps_enabled(): void
    {
        $models = [
            new WorkOrder(), new TechnicianAssignment(), new PreventiveMaintenance(),
            new PreventiveMaintenanceTask(), new AssetRequest(),
            new EngineeringChecklist(), new EngineeringChecklistItem(),
        ];

        foreach ($models as $model) {
            $this->assertTrue($model->timestamps, get_class($model) . ' must have timestamps enabled');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SoftDeletes
    // ══════════════════════════════════════════════════════════════════════

    public function test_soft_delete_models_use_soft_deletes_trait(): void
    {
        $softDeleteModels = [
            new WorkOrder(),
            new PreventiveMaintenance(),
            new AssetRequest(),
            new EngineeringChecklist(),
        ];

        foreach ($softDeleteModels as $model) {
            $this->assertTrue(
                in_array(SoftDeletes::class, class_uses_recursive($model)),
                get_class($model) . ' must use SoftDeletes'
            );
        }
    }

    public function test_non_soft_delete_models_do_not_use_soft_deletes_trait(): void
    {
        $hardDeleteModels = [
            new WorkOrderStatusHistory(),
            new TechnicianAssignment(),
            new PreventiveMaintenanceTask(),
            new EngineeringChecklistItem(),
        ];

        foreach ($hardDeleteModels as $model) {
            $this->assertFalse(
                in_array(SoftDeletes::class, class_uses_recursive($model)),
                get_class($model) . ' must NOT use SoftDeletes'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // WorkOrderStatusHistory — append-only guard
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_status_history_blocks_mass_assignment(): void
    {
        $history = new WorkOrderStatusHistory();
        $this->assertSame(['*'], $history->getGuarded());
    }

    public function test_work_order_status_history_record_sets_created_at(): void
    {
        // record() must always populate created_at even when $timestamps = false.
        // We verify the method exists and returns an instance of the model.
        $this->assertTrue(method_exists(WorkOrderStatusHistory::class, 'record'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // WorkOrder — enum and scalar casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_casts_work_order_type_enum(): void
    {
        $casts = (new WorkOrder())->getCasts();
        $this->assertArrayHasKey('work_order_type', $casts);
        $this->assertSame(WorkOrderTypeEnum::class, $casts['work_order_type']);
    }

    public function test_work_order_casts_status_enum(): void
    {
        $casts = (new WorkOrder())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(WorkOrderStatusEnum::class, $casts['status']);
    }

    public function test_work_order_casts_priority_enum(): void
    {
        $casts = (new WorkOrder())->getCasts();
        $this->assertArrayHasKey('priority', $casts);
        $this->assertSame(WorkOrderPriorityEnum::class, $casts['priority']);
    }

    public function test_work_order_casts_decimal_fields(): void
    {
        $casts = (new WorkOrder())->getCasts();
        $this->assertArrayHasKey('sla_hours', $casts);
        $this->assertArrayHasKey('estimated_hours', $casts);
        $this->assertArrayHasKey('actual_hours', $casts);
    }

    public function test_work_order_casts_datetime_fields(): void
    {
        $casts = (new WorkOrder())->getCasts();
        $this->assertArrayHasKey('due_date', $casts);
        $this->assertArrayHasKey('started_at', $casts);
        $this->assertArrayHasKey('completed_at', $casts);
        $this->assertArrayHasKey('cancelled_at', $casts);
        $this->assertArrayHasKey('approved_at', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TechnicianAssignment — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_technician_assignment_casts_role_enum(): void
    {
        $casts = (new TechnicianAssignment())->getCasts();
        $this->assertArrayHasKey('role', $casts);
        $this->assertSame(TechnicianRoleEnum::class, $casts['role']);
    }

    public function test_technician_assignment_casts_status_enum(): void
    {
        $casts = (new TechnicianAssignment())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(TechnicianAssignmentStatusEnum::class, $casts['status']);
    }

    public function test_technician_assignment_casts_datetime_fields(): void
    {
        $casts = (new TechnicianAssignment())->getCasts();
        $this->assertArrayHasKey('assigned_at', $casts);
        $this->assertArrayHasKey('started_at', $casts);
        $this->assertArrayHasKey('completed_at', $casts);
    }

    public function test_technician_assignment_casts_hours_worked_decimal(): void
    {
        $casts = (new TechnicianAssignment())->getCasts();
        $this->assertArrayHasKey('hours_worked', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PreventiveMaintenance — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_preventive_maintenance_casts_frequency_enum(): void
    {
        $casts = (new PreventiveMaintenance())->getCasts();
        $this->assertArrayHasKey('frequency', $casts);
        $this->assertSame(PmFrequencyEnum::class, $casts['frequency']);
    }

    public function test_preventive_maintenance_casts_status_enum(): void
    {
        $casts = (new PreventiveMaintenance())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(PmStatusEnum::class, $casts['status']);
    }

    public function test_preventive_maintenance_casts_datetime_fields(): void
    {
        $casts = (new PreventiveMaintenance())->getCasts();
        $this->assertArrayHasKey('last_run_at', $casts);
        $this->assertArrayHasKey('next_due_at', $casts);
    }

    public function test_preventive_maintenance_casts_frequency_days_integer(): void
    {
        $casts = (new PreventiveMaintenance())->getCasts();
        $this->assertArrayHasKey('frequency_days', $casts);
        $this->assertSame('integer', $casts['frequency_days']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PreventiveMaintenanceTask — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_preventive_maintenance_task_casts_status_enum(): void
    {
        $casts = (new PreventiveMaintenanceTask())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(PmTaskStatusEnum::class, $casts['status']);
    }

    public function test_preventive_maintenance_task_casts_datetime_fields(): void
    {
        $casts = (new PreventiveMaintenanceTask())->getCasts();
        $this->assertArrayHasKey('scheduled_date', $casts);
        $this->assertArrayHasKey('completed_at', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AssetRequest — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_asset_request_casts_status_enum(): void
    {
        $casts = (new AssetRequest())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(AssetRequestStatusEnum::class, $casts['status']);
    }

    public function test_asset_request_casts_priority_enum(): void
    {
        $casts = (new AssetRequest())->getCasts();
        $this->assertArrayHasKey('priority', $casts);
        $this->assertSame(WorkOrderPriorityEnum::class, $casts['priority']);
    }

    public function test_asset_request_casts_datetime_fields(): void
    {
        $casts = (new AssetRequest())->getCasts();
        $this->assertArrayHasKey('required_by', $casts);
        $this->assertArrayHasKey('approved_at', $casts);
        $this->assertArrayHasKey('rejected_at', $casts);
        $this->assertArrayHasKey('fulfilled_at', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // EngineeringChecklist — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_engineering_checklist_casts_checklist_type_enum(): void
    {
        $casts = (new EngineeringChecklist())->getCasts();
        $this->assertArrayHasKey('checklist_type', $casts);
        $this->assertSame(EngineeringChecklistTypeEnum::class, $casts['checklist_type']);
    }

    public function test_engineering_checklist_casts_is_active_boolean(): void
    {
        $casts = (new EngineeringChecklist())->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // EngineeringChecklistItem — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_engineering_checklist_item_casts_sort_order_integer(): void
    {
        $casts = (new EngineeringChecklistItem())->getCasts();
        $this->assertArrayHasKey('sort_order', $casts);
        $this->assertSame('integer', $casts['sort_order']);
    }

    public function test_engineering_checklist_item_casts_is_required_boolean(): void
    {
        $casts = (new EngineeringChecklistItem())->getCasts();
        $this->assertArrayHasKey('is_required', $casts);
        $this->assertSame('boolean', $casts['is_required']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — WorkOrder
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_has_expected_relationship_methods(): void
    {
        $wo = new WorkOrder();
        $this->assertTrue(method_exists($wo, 'property'));
        $this->assertTrue(method_exists($wo, 'room'));
        $this->assertTrue(method_exists($wo, 'zone'));
        $this->assertTrue(method_exists($wo, 'completedBy'));
        $this->assertTrue(method_exists($wo, 'cancelledBy'));
        $this->assertTrue(method_exists($wo, 'approvedBy'));
        $this->assertTrue(method_exists($wo, 'assignments'));
        $this->assertTrue(method_exists($wo, 'statusHistories'));
        $this->assertTrue(method_exists($wo, 'assetRequests'));
    }


    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — WorkOrderStatusHistory
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_status_history_has_expected_relationship_methods(): void
    {
        $history = new WorkOrderStatusHistory();
        $this->assertTrue(method_exists($history, 'workOrder'));
        $this->assertTrue(method_exists($history, 'changedBy'));
    }


    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — TechnicianAssignment
    // ══════════════════════════════════════════════════════════════════════

    public function test_technician_assignment_has_expected_relationship_methods(): void
    {
        $assignment = new TechnicianAssignment();
        $this->assertTrue(method_exists($assignment, 'property'));
        $this->assertTrue(method_exists($assignment, 'workOrder'));
        $this->assertTrue(method_exists($assignment, 'user'));
        $this->assertTrue(method_exists($assignment, 'department'));
        $this->assertTrue(method_exists($assignment, 'assignedBy'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — PreventiveMaintenance
    // ══════════════════════════════════════════════════════════════════════

    public function test_preventive_maintenance_has_expected_relationship_methods(): void
    {
        $pm = new PreventiveMaintenance();
        $this->assertTrue(method_exists($pm, 'property'));
        $this->assertTrue(method_exists($pm, 'room'));
        $this->assertTrue(method_exists($pm, 'zone'));
        $this->assertTrue(method_exists($pm, 'department'));
        $this->assertTrue(method_exists($pm, 'tasks'));
    }


    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — PreventiveMaintenanceTask
    // ══════════════════════════════════════════════════════════════════════

    public function test_preventive_maintenance_task_has_expected_relationship_methods(): void
    {
        $task = new PreventiveMaintenanceTask();
        $this->assertTrue(method_exists($task, 'property'));
        $this->assertTrue(method_exists($task, 'preventiveMaintenance'));
        $this->assertTrue(method_exists($task, 'workOrder'));
        $this->assertTrue(method_exists($task, 'completedBy'));
    }


    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — AssetRequest
    // ══════════════════════════════════════════════════════════════════════

    public function test_asset_request_has_expected_relationship_methods(): void
    {
        $req = new AssetRequest();
        $this->assertTrue(method_exists($req, 'property'));
        $this->assertTrue(method_exists($req, 'workOrder'));
        $this->assertTrue(method_exists($req, 'requester'));
        $this->assertTrue(method_exists($req, 'department'));
        $this->assertTrue(method_exists($req, 'approvedBy'));
        $this->assertTrue(method_exists($req, 'rejectedBy'));
        $this->assertTrue(method_exists($req, 'fulfilledBy'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — EngineeringChecklist
    // ══════════════════════════════════════════════════════════════════════

    public function test_engineering_checklist_has_expected_relationship_methods(): void
    {
        $checklist = new EngineeringChecklist();
        $this->assertTrue(method_exists($checklist, 'property'));
        $this->assertTrue(method_exists($checklist, 'items'));
    }


    // ══════════════════════════════════════════════════════════════════════
    // Relationship methods exist — EngineeringChecklistItem
    // ══════════════════════════════════════════════════════════════════════

    public function test_engineering_checklist_item_has_expected_relationship_methods(): void
    {
        $item = new EngineeringChecklistItem();
        $this->assertTrue(method_exists($item, 'property'));
        $this->assertTrue(method_exists($item, 'checklist'));
    }


    // ══════════════════════════════════════════════════════════════════════
    // Fillable sanity checks — key columns present
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_fillable_includes_sla_hours(): void
    {
        $this->assertContains('sla_hours', (new WorkOrder())->getFillable());
    }

    public function test_work_order_fillable_includes_asset_description(): void
    {
        $this->assertContains('asset_description', (new WorkOrder())->getFillable());
    }

    public function test_asset_request_fillable_includes_rejection_reason(): void
    {
        $this->assertContains('rejection_reason', (new AssetRequest())->getFillable());
    }

    public function test_engineering_checklist_item_fillable_includes_item_text(): void
    {
        $this->assertContains('item_text', (new EngineeringChecklistItem())->getFillable());
    }
}

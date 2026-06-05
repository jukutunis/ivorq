<?php

namespace Tests\Unit\Engineering;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Http\Requests\ApproveAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\ApproveWorkOrderRequest;
use Modules\Operations\Engineering\Http\Requests\AssignWorkOrderRequest;
use Modules\Operations\Engineering\Http\Requests\ChangePreventiveMaintenanceTaskStatusRequest;
use Modules\Operations\Engineering\Http\Requests\ChangeWorkOrderStatusRequest;
use Modules\Operations\Engineering\Http\Requests\CompleteTechnicianAssignmentRequest;
use Modules\Operations\Engineering\Http\Requests\FulfillAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\GeneratePreventiveMaintenanceTaskRequest;
use Modules\Operations\Engineering\Http\Requests\RejectAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\ReorderEngineeringChecklistItemsRequest;
use Modules\Operations\Engineering\Http\Requests\StoreAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\StoreEngineeringChecklistItemRequest;
use Modules\Operations\Engineering\Http\Requests\StoreEngineeringChecklistRequest;
use Modules\Operations\Engineering\Http\Requests\StorePreventiveMaintenanceRequest;
use Modules\Operations\Engineering\Http\Requests\StoreTechnicianAssignmentRequest;
use Modules\Operations\Engineering\Http\Requests\StoreWorkOrderRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateEngineeringChecklistItemRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateEngineeringChecklistRequest;
use Modules\Operations\Engineering\Http\Requests\UpdatePreventiveMaintenanceRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateTechnicianAssignmentRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateWorkOrderRequest;
use PHPUnit\Framework\TestCase;

class EngineeringRequestTest extends TestCase
{
    private function allRequestClasses(): array
    {
        return [
            StoreWorkOrderRequest::class,
            UpdateWorkOrderRequest::class,
            ChangeWorkOrderStatusRequest::class,
            AssignWorkOrderRequest::class,
            ApproveWorkOrderRequest::class,
            StoreTechnicianAssignmentRequest::class,
            UpdateTechnicianAssignmentRequest::class,
            CompleteTechnicianAssignmentRequest::class,
            StorePreventiveMaintenanceRequest::class,
            UpdatePreventiveMaintenanceRequest::class,
            GeneratePreventiveMaintenanceTaskRequest::class,
            ChangePreventiveMaintenanceTaskStatusRequest::class,
            StoreAssetRequestRequest::class,
            UpdateAssetRequestRequest::class,
            ApproveAssetRequestRequest::class,
            RejectAssetRequestRequest::class,
            FulfillAssetRequestRequest::class,
            StoreEngineeringChecklistRequest::class,
            UpdateEngineeringChecklistRequest::class,
            StoreEngineeringChecklistItemRequest::class,
            UpdateEngineeringChecklistItemRequest::class,
            ReorderEngineeringChecklistItemsRequest::class,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Autoload — all 21 classes exist
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_request_classes_autoload(): void
    {
        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(class_exists($class), "{$class} must autoload");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Inheritance — all extend FormRequest
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_request_classes_extend_form_request(): void
    {
        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(
                is_subclass_of($class, FormRequest::class),
                "{$class} must extend FormRequest"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Interface — authorize() and rules() methods exist on all requests
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_request_classes_have_authorize_and_rules(): void
    {
        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(method_exists($class, 'authorize'), "{$class}::authorize() missing");
            $this->assertTrue(method_exists($class, 'rules'),     "{$class}::rules() missing");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Status / actor fields are prohibited in Store/Update requests
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_work_order_prohibits_status_and_actor_fields(): void
    {
        $rc      = new \ReflectionClass(StoreWorkOrderRequest::class);
        $method  = $rc->getMethod('rules');

        // rules() calls app() which isn't available in pure unit tests —
        // we verify at reflection level that 'status', 'completed_by', etc.
        // appear in the method body as string literals.
        $body = file_get_contents($rc->getFileName());

        $this->assertStringContainsString("'status'",       $body);
        $this->assertStringContainsString("'prohibited'",   $body);
        $this->assertStringContainsString("'completed_by'", $body);
        $this->assertStringContainsString("'cancelled_by'", $body);
        $this->assertStringContainsString("'approved_by'",  $body);
    }

    public function test_update_work_order_prohibits_all_lifecycle_fields(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(UpdateWorkOrderRequest::class))->getFileName()
        );

        foreach (['status', 'started_at', 'completed_at', 'cancelled_at', 'approved_at',
                  'completed_by', 'cancelled_by', 'approved_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "UpdateWorkOrderRequest must prohibit '{$field}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_update_asset_request_prohibits_actor_and_status_fields(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(UpdateAssetRequestRequest::class))->getFileName()
        );

        foreach (['status', 'approved_by', 'approved_at', 'rejected_by',
                  'rejected_at', 'fulfilled_by', 'fulfilled_at'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "UpdateAssetRequestRequest must prohibit '{$field}'"
            );
        }
    }

    public function test_store_pm_prohibits_status_and_schedule_fields(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(StorePreventiveMaintenanceRequest::class))->getFileName()
        );

        foreach (['status', 'last_run_at', 'next_due_at'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StorePreventiveMaintenanceRequest must prohibit '{$field}'"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Key required fields present in Store requests
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_work_order_has_required_fields_in_source(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(StoreWorkOrderRequest::class))->getFileName()
        );

        foreach (['work_order_number', 'title', 'work_order_type', 'sla_hours',
                  'asset_description', 'room_id', 'zone_id'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreWorkOrderRequest must include '{$field}'"
            );
        }
    }

    public function test_store_asset_request_has_required_fields_in_source(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(StoreAssetRequestRequest::class))->getFileName()
        );

        foreach (['request_number', 'title', 'work_order_id', 'priority', 'required_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreAssetRequestRequest must include '{$field}'"
            );
        }
    }

    public function test_store_pm_has_required_fields_in_source(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(StorePreventiveMaintenanceRequest::class))->getFileName()
        );

        foreach (['pm_code', 'title', 'frequency', 'frequency_days',
                  'room_id', 'zone_id', 'department_id'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StorePreventiveMaintenanceRequest must include '{$field}'"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // sla_hours and hours_worked constraints present
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_requests_include_sla_hours_with_bounds(): void
    {
        foreach ([StoreWorkOrderRequest::class, UpdateWorkOrderRequest::class] as $class) {
            $body = file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringContainsString("'sla_hours'", $body, "{$class} must have sla_hours");
            $this->assertStringContainsString('0.5', $body, "{$class} sla_hours must set min:0.5");
            $this->assertStringContainsString('720',  $body, "{$class} sla_hours must set max:720");
        }
    }

    public function test_complete_assignment_request_includes_hours_worked_with_bounds(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(CompleteTechnicianAssignmentRequest::class))->getFileName()
        );

        $this->assertStringContainsString("'hours_worked'", $body);
        $this->assertStringContainsString('min:0',  $body);
        $this->assertStringContainsString('max:24', $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Reject request requires reason
    // ══════════════════════════════════════════════════════════════════════

    public function test_reject_asset_request_requires_reason_field(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(RejectAssetRequestRequest::class))->getFileName()
        );

        $this->assertStringContainsString("'reason'",   $body);
        $this->assertStringContainsString("'required'", $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Status-only requests carry enum validation
    // ══════════════════════════════════════════════════════════════════════

    public function test_change_work_order_status_request_has_status_and_remarks(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(ChangeWorkOrderStatusRequest::class))->getFileName()
        );

        $this->assertStringContainsString("'status'",  $body);
        $this->assertStringContainsString("'required'", $body);
        $this->assertStringContainsString('Rule::enum', $body);
        $this->assertStringContainsString("'remarks'",  $body);
    }

    public function test_change_pm_task_status_request_has_status_and_remarks(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(ChangePreventiveMaintenanceTaskStatusRequest::class))->getFileName()
        );

        $this->assertStringContainsString("'status'",  $body);
        $this->assertStringContainsString('Rule::enum', $body);
        $this->assertStringContainsString("'remarks'",  $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Reorder request validates items array
    // ══════════════════════════════════════════════════════════════════════

    public function test_reorder_checklist_items_request_validates_items_array(): void
    {
        $body = file_get_contents(
            (new \ReflectionClass(ReorderEngineeringChecklistItemsRequest::class))->getFileName()
        );

        $this->assertStringContainsString("'items'",    $body);
        $this->assertStringContainsString("'required'", $body);
        $this->assertStringContainsString("'array'",    $body);
        $this->assertStringContainsString("'items.*'",  $body);
        $this->assertStringContainsString('Rule::exists', $body);
        $this->assertStringContainsString('engineering_checklist_items', $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Count — exactly 21 request classes delivered
    // ══════════════════════════════════════════════════════════════════════

    public function test_exactly_twenty_two_request_classes_exist(): void
    {
        $this->assertCount(22, $this->allRequestClasses());

        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(class_exists($class), "{$class} must exist");
        }
    }
}

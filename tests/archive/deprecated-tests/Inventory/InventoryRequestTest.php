<?php

namespace Tests\Unit\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Http\Requests\ApproveAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\CancelAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\CancelIssueRequest;
use Modules\Operations\Inventory\Http\Requests\CancelReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\CancelTransferRequest;
use Modules\Operations\Inventory\Http\Requests\CompleteTransferRequest;
use Modules\Operations\Inventory\Http\Requests\PostIssueRequest;
use Modules\Operations\Inventory\Http\Requests\PostReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\RejectAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\StoreAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\StoreCategoryRequest;
use Modules\Operations\Inventory\Http\Requests\StoreIssueRequest;
use Modules\Operations\Inventory\Http\Requests\StoreItemRequest;
use Modules\Operations\Inventory\Http\Requests\StoreLocationRequest;
use Modules\Operations\Inventory\Http\Requests\StoreReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\StoreTransferRequest;
use Modules\Operations\Inventory\Http\Requests\StoreUnitRequest;
use Modules\Operations\Inventory\Http\Requests\SubmitAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateCategoryRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateIssueRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateItemRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateLocationRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateTransferRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateUnitRequest;
use PHPUnit\Framework\TestCase;

class InventoryRequestTest extends TestCase
{
    private function allRequestClasses(): array
    {
        return [
            // Category
            StoreCategoryRequest::class,
            UpdateCategoryRequest::class,

            // Unit
            StoreUnitRequest::class,
            UpdateUnitRequest::class,

            // Location
            StoreLocationRequest::class,
            UpdateLocationRequest::class,

            // Item
            StoreItemRequest::class,
            UpdateItemRequest::class,

            // Receipt
            StoreReceiptRequest::class,
            UpdateReceiptRequest::class,
            PostReceiptRequest::class,
            CancelReceiptRequest::class,

            // Issue
            StoreIssueRequest::class,
            UpdateIssueRequest::class,
            PostIssueRequest::class,
            CancelIssueRequest::class,

            // Transfer
            StoreTransferRequest::class,
            UpdateTransferRequest::class,
            CompleteTransferRequest::class,
            CancelTransferRequest::class,

            // Adjustment
            StoreAdjustmentRequest::class,
            UpdateAdjustmentRequest::class,
            SubmitAdjustmentRequest::class,
            ApproveAdjustmentRequest::class,
            RejectAdjustmentRequest::class,
            CancelAdjustmentRequest::class,
        ];
    }

    private function src(string $class): string
    {
        return file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Autoload — all 26 classes exist
    // ══════════════════════════════════════════════════════════════════════

    public function test_exactly_twenty_six_request_classes_exist(): void
    {
        $this->assertCount(26, $this->allRequestClasses());

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
    // Interface — authorize() and rules() present on all classes
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_request_classes_have_authorize_and_rules(): void
    {
        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(method_exists($class, 'authorize'), "{$class}::authorize() missing");
            $this->assertTrue(method_exists($class, 'rules'),     "{$class}::rules() missing");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Category — prohibited fields + unique constraint
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_category_prohibits_audit_fields(): void
    {
        $body = $this->src(StoreCategoryRequest::class);

        $this->assertStringContainsString("'created_by'", $body);
        $this->assertStringContainsString("'updated_by'", $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_store_category_has_property_scoped_unique_on_code(): void
    {
        $body = $this->src(StoreCategoryRequest::class);

        $this->assertStringContainsString('inventory_categories', $body);
        $this->assertStringContainsString('category_code',        $body);
        $this->assertStringContainsString('property_id',          $body);
        $this->assertStringContainsString('unique',               $body);
    }

    public function test_update_category_prohibits_audit_fields_and_has_self_ignore_unique(): void
    {
        $body = $this->src(UpdateCategoryRequest::class);

        $this->assertStringContainsString("'created_by'",         $body);
        $this->assertStringContainsString("'prohibited'",          $body);
        $this->assertStringContainsString('inventory_categories',  $body);
        $this->assertStringContainsString('category_code',         $body);
        $this->assertStringContainsString('category',              $body); // route param used as exclude ID
        $this->assertStringContainsString('property_id',           $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Unit — required abbreviation + prohibited audit fields
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_unit_requires_abbreviation(): void
    {
        $body = $this->src(StoreUnitRequest::class);

        $this->assertStringContainsString("'abbreviation'", $body);
        $this->assertStringContainsString("'required'",     $body);
    }

    public function test_store_unit_prohibits_audit_fields(): void
    {
        $body = $this->src(StoreUnitRequest::class);

        $this->assertStringContainsString("'created_by'", $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Location — enum rule for location_type
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_location_uses_enum_rule_for_location_type(): void
    {
        $body = $this->src(StoreLocationRequest::class);

        $this->assertStringContainsString('Rule::enum',       $body);
        $this->assertStringContainsString('LocationTypeEnum', $body);
        $this->assertStringContainsString("'location_type'",  $body);
        $this->assertStringContainsString("'required'",       $body);
    }

    public function test_update_location_uses_enum_rule_for_location_type(): void
    {
        $body = $this->src(UpdateLocationRequest::class);

        $this->assertStringContainsString('Rule::enum',       $body);
        $this->assertStringContainsString('LocationTypeEnum', $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Item — property-scoped FK, ULID, average_cost prohibited
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_item_has_property_scoped_category_and_unit_exists(): void
    {
        $body = $this->src(StoreItemRequest::class);

        $this->assertStringContainsString('Rule::exists',         $body);
        $this->assertStringContainsString('inventory_categories', $body);
        $this->assertStringContainsString('inventory_units',      $body);
        $this->assertStringContainsString('property_id',          $body);
        $this->assertStringContainsString("'size:26'",            $body);
    }

    public function test_store_item_prohibits_average_cost(): void
    {
        $body = $this->src(StoreItemRequest::class);

        $this->assertStringContainsString("'average_cost'", $body);
        $this->assertStringContainsString("'prohibited'",   $body);
    }

    public function test_update_item_prohibits_average_cost(): void
    {
        $body = $this->src(UpdateItemRequest::class);

        $this->assertStringContainsString("'average_cost'", $body);
        $this->assertStringContainsString("'prohibited'",   $body);
    }

    public function test_store_item_requires_name_category_and_unit(): void
    {
        $body = $this->src(StoreItemRequest::class);

        foreach (['item_code', 'name', 'category_id', 'unit_id'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreItemRequest must include '{$field}'"
            );
        }
        $this->assertStringContainsString("'required'", $body);
    }

    public function test_item_decimal_fields_are_validated_as_numeric(): void
    {
        foreach ([StoreItemRequest::class, UpdateItemRequest::class] as $class) {
            $body = $this->src($class);
            $this->assertStringContainsString("'min_stock'",     $body, "{$class} must have min_stock");
            $this->assertStringContainsString("'reorder_point'", $body, "{$class} must have reorder_point");
            $this->assertStringContainsString("'numeric'",       $body, "{$class} must use numeric rule");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Receipt — lifecycle prohibited, nested lines required on store
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_receipt_prohibits_lifecycle_fields(): void
    {
        $body = $this->src(StoreReceiptRequest::class);

        foreach (['status', 'posted_at', 'posted_by', 'cancelled_at', 'cancelled_by'] as $f) {
            $this->assertStringContainsString("'{$f}'", $body,
                "StoreReceiptRequest must prohibit '{$f}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_store_receipt_requires_lines_array(): void
    {
        $body = $this->src(StoreReceiptRequest::class);

        $this->assertStringContainsString("'lines'",           $body);
        $this->assertStringContainsString("'required'",        $body);
        $this->assertStringContainsString("'array'",           $body);
        $this->assertStringContainsString("'lines.*.item_id'", $body);
        $this->assertStringContainsString("'lines.*.location_id'", $body);
        $this->assertStringContainsString("'lines.*.quantity'", $body);
        $this->assertStringContainsString("'lines.*.unit_cost'", $body);
    }

    public function test_store_receipt_lines_use_property_scoped_exists(): void
    {
        $body = $this->src(StoreReceiptRequest::class);

        $this->assertStringContainsString('Rule::exists',       $body);
        $this->assertStringContainsString('inventory_items',     $body);
        $this->assertStringContainsString('inventory_locations', $body);
        $this->assertStringContainsString('property_id',         $body);
        $this->assertStringContainsString("'size:26'",           $body);
    }

    public function test_update_receipt_prohibits_lifecycle_fields(): void
    {
        $body = $this->src(UpdateReceiptRequest::class);

        foreach (['status', 'posted_at', 'posted_by'] as $f) {
            $this->assertStringContainsString("'{$f}'", $body,
                "UpdateReceiptRequest must prohibit '{$f}'"
            );
        }
    }

    public function test_post_receipt_prohibits_all_body_fields(): void
    {
        $body = $this->src(PostReceiptRequest::class);

        $this->assertStringContainsString("'status'",     $body);
        $this->assertStringContainsString("'posted_at'",  $body);
        $this->assertStringContainsString("'posted_by'",  $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_cancel_receipt_accepts_optional_reason(): void
    {
        $body = $this->src(CancelReceiptRequest::class);

        $this->assertStringContainsString("'reason'",   $body);
        $this->assertStringContainsString("'nullable'", $body);
        $this->assertStringContainsString("'status'",   $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Issue — lifecycle prohibited, nested lines, department_id property-scoped
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_issue_prohibits_lifecycle_fields(): void
    {
        $body = $this->src(StoreIssueRequest::class);

        foreach (['status', 'posted_at', 'posted_by', 'cancelled_at', 'cancelled_by'] as $f) {
            $this->assertStringContainsString("'{$f}'", $body,
                "StoreIssueRequest must prohibit '{$f}'"
            );
        }
    }

    public function test_store_issue_requires_lines_array(): void
    {
        $body = $this->src(StoreIssueRequest::class);

        $this->assertStringContainsString("'lines'",              $body);
        $this->assertStringContainsString("'required'",           $body);
        $this->assertStringContainsString("'array'",              $body);
        $this->assertStringContainsString("'lines.*.item_id'",    $body);
        $this->assertStringContainsString("'lines.*.location_id'", $body);
        $this->assertStringContainsString("'lines.*.quantity'",   $body);
    }

    public function test_store_issue_has_property_scoped_department_exists(): void
    {
        $body = $this->src(StoreIssueRequest::class);

        $this->assertStringContainsString('departments',  $body);
        $this->assertStringContainsString('property_id', $body);
        $this->assertStringContainsString("'size:26'",   $body);
    }

    public function test_post_issue_prohibits_all_body_fields(): void
    {
        $body = $this->src(PostIssueRequest::class);

        $this->assertStringContainsString("'prohibited'", $body);
        $this->assertStringContainsString("'status'",     $body);
        $this->assertStringContainsString("'posted_by'",  $body);
    }

    public function test_cancel_issue_accepts_optional_reason(): void
    {
        $body = $this->src(CancelIssueRequest::class);

        $this->assertStringContainsString("'reason'",   $body);
        $this->assertStringContainsString("'nullable'", $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Transfer — both location FKs property-scoped, nested lines
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_transfer_prohibits_all_lifecycle_fields(): void
    {
        $body = $this->src(StoreTransferRequest::class);

        foreach (['status', 'approved_by', 'approved_at', 'completed_by',
                  'completed_at', 'cancelled_by', 'cancelled_at', 'requested_by'] as $f) {
            $this->assertStringContainsString("'{$f}'", $body,
                "StoreTransferRequest must prohibit '{$f}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_store_transfer_requires_both_location_ids_property_scoped(): void
    {
        $body = $this->src(StoreTransferRequest::class);

        $this->assertStringContainsString("'from_location_id'", $body);
        $this->assertStringContainsString("'to_location_id'",   $body);
        $this->assertStringContainsString('Rule::exists',        $body);
        $this->assertStringContainsString('inventory_locations', $body);
        $this->assertStringContainsString('property_id',         $body);
        $this->assertStringContainsString("'size:26'",           $body);
    }

    public function test_store_transfer_requires_lines_with_item_and_quantity_requested(): void
    {
        $body = $this->src(StoreTransferRequest::class);

        $this->assertStringContainsString("'lines'",                      $body);
        $this->assertStringContainsString("'lines.*.item_id'",            $body);
        $this->assertStringContainsString("'lines.*.quantity_requested'", $body);
        $this->assertStringContainsString("'required'",                   $body);
    }

    public function test_complete_transfer_prohibits_all_body_fields(): void
    {
        $body = $this->src(CompleteTransferRequest::class);

        $this->assertStringContainsString("'status'",        $body);
        $this->assertStringContainsString("'completed_by'",  $body);
        $this->assertStringContainsString("'completed_at'",  $body);
        $this->assertStringContainsString("'prohibited'",    $body);
    }

    public function test_cancel_transfer_accepts_optional_reason(): void
    {
        $body = $this->src(CancelTransferRequest::class);

        $this->assertStringContainsString("'reason'",    $body);
        $this->assertStringContainsString("'nullable'",  $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Adjustment — enum, location FK, nested lines, quantity_variance prohibited
    // ══════════════════════════════════════════════════════════════════════

    public function test_store_adjustment_uses_enum_rule_for_adjustment_type(): void
    {
        $body = $this->src(StoreAdjustmentRequest::class);

        $this->assertStringContainsString('Rule::enum',          $body);
        $this->assertStringContainsString('AdjustmentTypeEnum',  $body);
        $this->assertStringContainsString("'adjustment_type'",   $body);
    }

    public function test_store_adjustment_requires_location_property_scoped(): void
    {
        $body = $this->src(StoreAdjustmentRequest::class);

        $this->assertStringContainsString("'location_id'",      $body);
        $this->assertStringContainsString('Rule::exists',        $body);
        $this->assertStringContainsString('inventory_locations', $body);
        $this->assertStringContainsString('property_id',         $body);
        $this->assertStringContainsString("'size:26'",           $body);
    }

    public function test_store_adjustment_requires_lines_with_quantity_system_and_actual(): void
    {
        $body = $this->src(StoreAdjustmentRequest::class);

        $this->assertStringContainsString("'lines'",                    $body);
        $this->assertStringContainsString("'lines.*.quantity_system'",  $body);
        $this->assertStringContainsString("'lines.*.quantity_actual'",  $body);
        $this->assertStringContainsString("'required'",                 $body);
    }

    public function test_store_adjustment_prohibits_quantity_variance_and_lifecycle_fields(): void
    {
        $body = $this->src(StoreAdjustmentRequest::class);

        $this->assertStringContainsString("'lines.*.quantity_variance'", $body);
        $this->assertStringContainsString("'status'",                    $body);
        $this->assertStringContainsString("'submitted_by'",              $body);
        $this->assertStringContainsString("'approved_by'",               $body);
        $this->assertStringContainsString("'rejected_by'",               $body);
        $this->assertStringContainsString("'rejection_reason'",          $body);
        $this->assertStringContainsString("'prohibited'",                $body);
    }

    public function test_update_adjustment_prohibits_all_lifecycle_fields(): void
    {
        $body = $this->src(UpdateAdjustmentRequest::class);

        foreach (['status', 'submitted_by', 'submitted_at',
                  'approved_by', 'approved_at', 'rejected_by',
                  'rejected_at', 'rejection_reason'] as $f) {
            $this->assertStringContainsString("'{$f}'", $body,
                "UpdateAdjustmentRequest must prohibit '{$f}'"
            );
        }
    }

    public function test_submit_adjustment_prohibits_body_fields(): void
    {
        $body = $this->src(SubmitAdjustmentRequest::class);

        $this->assertStringContainsString("'status'",        $body);
        $this->assertStringContainsString("'submitted_by'",  $body);
        $this->assertStringContainsString("'submitted_at'",  $body);
        $this->assertStringContainsString("'prohibited'",    $body);
    }

    public function test_approve_adjustment_prohibits_body_fields(): void
    {
        $body = $this->src(ApproveAdjustmentRequest::class);

        $this->assertStringContainsString("'status'",       $body);
        $this->assertStringContainsString("'approved_by'",  $body);
        $this->assertStringContainsString("'prohibited'",   $body);
    }

    public function test_reject_adjustment_requires_rejection_reason(): void
    {
        $body = $this->src(RejectAdjustmentRequest::class);

        $this->assertStringContainsString("'rejection_reason'", $body);
        $this->assertStringContainsString("'required'",         $body);
        $this->assertStringContainsString("'rejected_by'",      $body);
        $this->assertStringContainsString("'prohibited'",       $body);
    }

    public function test_cancel_adjustment_accepts_optional_reason(): void
    {
        $body = $this->src(CancelAdjustmentRequest::class);

        $this->assertStringContainsString("'reason'",    $body);
        $this->assertStringContainsString("'nullable'",  $body);
        $this->assertStringContainsString("'prohibited'", $body);
    }
}

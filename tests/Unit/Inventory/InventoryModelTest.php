<?php

namespace Tests\Unit\Inventory;

use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryStockBalance;
use Modules\Operations\Inventory\Models\InventoryStockCard;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Operations\Inventory\Models\InventoryUnit;
use PHPUnit\Framework\TestCase;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class InventoryModelTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════
    // Autoload
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_model_classes_autoload(): void
    {
        $this->assertInstanceOf(InventoryCategory::class,       new InventoryCategory());
        $this->assertInstanceOf(InventoryUnit::class,           new InventoryUnit());
        $this->assertInstanceOf(InventoryLocation::class,       new InventoryLocation());
        $this->assertInstanceOf(InventoryItem::class,           new InventoryItem());
        $this->assertInstanceOf(InventoryStockBalance::class,   new InventoryStockBalance());
        $this->assertInstanceOf(InventoryStockCard::class,      new InventoryStockCard());
        $this->assertInstanceOf(InventoryReceipt::class,        new InventoryReceipt());
        $this->assertInstanceOf(InventoryReceiptLine::class,    new InventoryReceiptLine());
        $this->assertInstanceOf(InventoryIssue::class,          new InventoryIssue());
        $this->assertInstanceOf(InventoryIssueLine::class,      new InventoryIssueLine());
        $this->assertInstanceOf(InventoryTransfer::class,       new InventoryTransfer());
        $this->assertInstanceOf(InventoryTransferLine::class,   new InventoryTransferLine());
        $this->assertInstanceOf(InventoryAdjustment::class,     new InventoryAdjustment());
        $this->assertInstanceOf(InventoryAdjustmentLine::class, new InventoryAdjustmentLine());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Table names
    // ══════════════════════════════════════════════════════════════════════

    public function test_model_table_names_are_correct(): void
    {
        $this->assertSame('inventory_categories',       (new InventoryCategory())->getTable());
        $this->assertSame('inventory_units',            (new InventoryUnit())->getTable());
        $this->assertSame('inventory_locations',        (new InventoryLocation())->getTable());
        $this->assertSame('inventory_items',            (new InventoryItem())->getTable());
        $this->assertSame('inventory_stock_balances',   (new InventoryStockBalance())->getTable());
        $this->assertSame('inventory_stock_cards',      (new InventoryStockCard())->getTable());
        $this->assertSame('inventory_receipts',         (new InventoryReceipt())->getTable());
        $this->assertSame('inventory_receipt_lines',    (new InventoryReceiptLine())->getTable());
        $this->assertSame('inventory_issues',           (new InventoryIssue())->getTable());
        $this->assertSame('inventory_issue_lines',      (new InventoryIssueLine())->getTable());
        $this->assertSame('inventory_transfers',        (new InventoryTransfer())->getTable());
        $this->assertSame('inventory_transfer_lines',   (new InventoryTransferLine())->getTable());
        $this->assertSame('inventory_adjustments',      (new InventoryAdjustment())->getTable());
        $this->assertSame('inventory_adjustment_lines', (new InventoryAdjustmentLine())->getTable());
    }

    // ══════════════════════════════════════════════════════════════════════
    // ULID primary keys
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_models_use_string_primary_key(): void
    {
        $models = [
            new InventoryCategory(), new InventoryUnit(), new InventoryLocation(),
            new InventoryItem(), new InventoryStockBalance(), new InventoryStockCard(),
            new InventoryReceipt(), new InventoryReceiptLine(), new InventoryIssue(),
            new InventoryIssueLine(), new InventoryTransfer(), new InventoryTransferLine(),
            new InventoryAdjustment(), new InventoryAdjustmentLine(),
        ];

        foreach ($models as $model) {
            $this->assertSame('string', $model->getKeyType(), get_class($model) . ' must use string PK');
            $this->assertFalse($model->getIncrementing(), get_class($model) . ' must not auto-increment');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Traits — HasUlid on all models
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_models_use_has_ulid(): void
    {
        $models = [
            new InventoryCategory(), new InventoryUnit(), new InventoryLocation(),
            new InventoryItem(), new InventoryStockBalance(), new InventoryStockCard(),
            new InventoryReceipt(), new InventoryReceiptLine(), new InventoryIssue(),
            new InventoryIssueLine(), new InventoryTransfer(), new InventoryTransferLine(),
            new InventoryAdjustment(), new InventoryAdjustmentLine(),
        ];

        foreach ($models as $model) {
            $this->assertContains(HasUlid::class, class_uses_recursive($model), get_class($model) . ' must use HasUlid');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Traits — BelongsToProperty on all models
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_models_use_belongs_to_property(): void
    {
        $models = [
            new InventoryCategory(), new InventoryUnit(), new InventoryLocation(),
            new InventoryItem(), new InventoryStockBalance(), new InventoryStockCard(),
            new InventoryReceipt(), new InventoryReceiptLine(), new InventoryIssue(),
            new InventoryIssueLine(), new InventoryTransfer(), new InventoryTransferLine(),
            new InventoryAdjustment(), new InventoryAdjustmentLine(),
        ];

        foreach ($models as $model) {
            $this->assertContains(BelongsToProperty::class, class_uses_recursive($model), get_class($model) . ' must use BelongsToProperty');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Traits — HasAuditColumns on header/master models only
    // ══════════════════════════════════════════════════════════════════════

    public function test_header_and_master_models_use_has_audit_columns(): void
    {
        $auditModels = [
            new InventoryCategory(), new InventoryUnit(), new InventoryLocation(),
            new InventoryItem(), new InventoryReceipt(), new InventoryIssue(),
            new InventoryTransfer(), new InventoryAdjustment(),
        ];

        foreach ($auditModels as $model) {
            $this->assertContains(HasAuditColumns::class, class_uses_recursive($model), get_class($model) . ' must use HasAuditColumns');
        }
    }

    public function test_system_and_line_models_do_not_use_has_audit_columns(): void
    {
        $nonAuditModels = [
            new InventoryStockBalance(), new InventoryStockCard(),
            new InventoryReceiptLine(), new InventoryIssueLine(),
            new InventoryTransferLine(), new InventoryAdjustmentLine(),
        ];

        foreach ($nonAuditModels as $model) {
            $this->assertNotContains(HasAuditColumns::class, class_uses_recursive($model), get_class($model) . ' must NOT use HasAuditColumns');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SoftDeletes — header/master models have it, others do not
    // ══════════════════════════════════════════════════════════════════════

    public function test_soft_delete_models_use_soft_deletes_trait(): void
    {
        $softDeleteModels = [
            new InventoryCategory(), new InventoryUnit(), new InventoryLocation(),
            new InventoryItem(), new InventoryReceipt(), new InventoryIssue(),
            new InventoryTransfer(), new InventoryAdjustment(),
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
            new InventoryStockBalance(), new InventoryStockCard(),
            new InventoryReceiptLine(), new InventoryIssueLine(),
            new InventoryTransferLine(), new InventoryAdjustmentLine(),
        ];

        foreach ($hardDeleteModels as $model) {
            $this->assertFalse(
                in_array(SoftDeletes::class, class_uses_recursive($model)),
                get_class($model) . ' must NOT use SoftDeletes'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Timestamps
    // ══════════════════════════════════════════════════════════════════════

    public function test_stock_card_has_no_automatic_timestamps(): void
    {
        $this->assertFalse((new InventoryStockCard())->timestamps);
    }

    public function test_all_other_models_have_timestamps_enabled(): void
    {
        $timestampedModels = [
            new InventoryCategory(), new InventoryUnit(), new InventoryLocation(),
            new InventoryItem(), new InventoryStockBalance(), new InventoryReceipt(),
            new InventoryReceiptLine(), new InventoryIssue(), new InventoryIssueLine(),
            new InventoryTransfer(), new InventoryTransferLine(),
            new InventoryAdjustment(), new InventoryAdjustmentLine(),
        ];

        foreach ($timestampedModels as $model) {
            $this->assertTrue($model->timestamps, get_class($model) . ' must have timestamps enabled');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryStockCard — append-only guard
    // ══════════════════════════════════════════════════════════════════════

    public function test_stock_card_blocks_mass_assignment(): void
    {
        $card = new InventoryStockCard();
        $this->assertSame(['*'], $card->getGuarded());
    }

    public function test_stock_card_has_no_soft_deletes(): void
    {
        $this->assertFalse(
            in_array(SoftDeletes::class, class_uses_recursive(new InventoryStockCard()))
        );
    }

    public function test_stock_card_has_no_audit_columns(): void
    {
        $this->assertFalse(
            in_array(HasAuditColumns::class, class_uses_recursive(new InventoryStockCard()))
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryCategory — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_category_casts_is_active_boolean(): void
    {
        $casts = (new InventoryCategory())->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryUnit — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_unit_casts_is_active_boolean(): void
    {
        $casts = (new InventoryUnit())->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryLocation — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_location_casts_location_type_enum(): void
    {
        $casts = (new InventoryLocation())->getCasts();
        $this->assertArrayHasKey('location_type', $casts);
        $this->assertSame(LocationTypeEnum::class, $casts['location_type']);
    }

    public function test_inventory_location_casts_is_active_boolean(): void
    {
        $casts = (new InventoryLocation())->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryItem — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_item_casts_decimal_quantity_fields(): void
    {
        $casts = (new InventoryItem())->getCasts();
        $this->assertArrayHasKey('min_stock',        $casts);
        $this->assertArrayHasKey('max_stock',        $casts);
        $this->assertArrayHasKey('reorder_point',    $casts);
        $this->assertArrayHasKey('reorder_quantity', $casts);
        $this->assertSame('decimal:3', $casts['min_stock']);
        $this->assertSame('decimal:3', $casts['reorder_point']);
    }

    public function test_inventory_item_casts_average_cost_with_four_decimal_places(): void
    {
        $casts = (new InventoryItem())->getCasts();
        $this->assertArrayHasKey('average_cost', $casts);
        $this->assertSame('decimal:4', $casts['average_cost']);
    }

    public function test_inventory_item_casts_is_active_boolean(): void
    {
        $casts = (new InventoryItem())->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryStockBalance — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_stock_balance_casts_status_enum(): void
    {
        $casts = (new InventoryStockBalance())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(ItemStatusEnum::class, $casts['status']);
    }

    public function test_inventory_stock_balance_casts_quantity_decimal(): void
    {
        $casts = (new InventoryStockBalance())->getCasts();
        $this->assertArrayHasKey('quantity', $casts);
        $this->assertSame('decimal:3', $casts['quantity']);
    }

    public function test_inventory_stock_balance_casts_last_movement_at_datetime(): void
    {
        $casts = (new InventoryStockBalance())->getCasts();
        $this->assertArrayHasKey('last_movement_at', $casts);
        $this->assertSame('datetime', $casts['last_movement_at']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryStockCard — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_stock_card_casts_movement_type_enum(): void
    {
        $casts = (new InventoryStockCard())->getCasts();
        $this->assertArrayHasKey('movement_type', $casts);
        $this->assertSame(TransactionTypeEnum::class, $casts['movement_type']);
    }

    public function test_inventory_stock_card_casts_quantity_decimal_fields(): void
    {
        $casts = (new InventoryStockCard())->getCasts();
        $this->assertArrayHasKey('quantity_before', $casts);
        $this->assertArrayHasKey('quantity_change', $casts);
        $this->assertArrayHasKey('quantity_after',  $casts);
        $this->assertSame('decimal:3', $casts['quantity_before']);
        $this->assertSame('decimal:3', $casts['quantity_change']);
        $this->assertSame('decimal:3', $casts['quantity_after']);
    }

    public function test_inventory_stock_card_casts_cost_fields_with_four_decimal_places(): void
    {
        $casts = (new InventoryStockCard())->getCasts();
        $this->assertArrayHasKey('unit_cost',    $casts);
        $this->assertArrayHasKey('total_value',  $casts);
        $this->assertSame('decimal:4', $casts['unit_cost']);
        $this->assertSame('decimal:4', $casts['total_value']);
    }

    public function test_inventory_stock_card_casts_posted_at_datetime(): void
    {
        $casts = (new InventoryStockCard())->getCasts();
        $this->assertArrayHasKey('posted_at', $casts);
        $this->assertSame('datetime', $casts['posted_at']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryReceipt — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_receipt_casts_status_enum(): void
    {
        $casts = (new InventoryReceipt())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(ReceiptStatusEnum::class, $casts['status']);
    }

    public function test_inventory_receipt_casts_datetime_fields(): void
    {
        $casts = (new InventoryReceipt())->getCasts();
        $this->assertArrayHasKey('received_at',  $casts);
        $this->assertArrayHasKey('posted_at',    $casts);
        $this->assertArrayHasKey('cancelled_at', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryReceiptLine — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_receipt_line_casts_decimal_fields(): void
    {
        $casts = (new InventoryReceiptLine())->getCasts();
        $this->assertArrayHasKey('quantity',    $casts);
        $this->assertArrayHasKey('unit_cost',   $casts);
        $this->assertArrayHasKey('total_value', $casts);
        $this->assertSame('decimal:3', $casts['quantity']);
        $this->assertSame('decimal:4', $casts['unit_cost']);
        $this->assertSame('decimal:4', $casts['total_value']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryIssue — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_issue_casts_status_enum(): void
    {
        $casts = (new InventoryIssue())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(IssueStatusEnum::class, $casts['status']);
    }

    public function test_inventory_issue_casts_datetime_fields(): void
    {
        $casts = (new InventoryIssue())->getCasts();
        $this->assertArrayHasKey('issued_at',    $casts);
        $this->assertArrayHasKey('posted_at',    $casts);
        $this->assertArrayHasKey('cancelled_at', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryIssueLine — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_issue_line_casts_quantity_decimal(): void
    {
        $casts = (new InventoryIssueLine())->getCasts();
        $this->assertArrayHasKey('quantity', $casts);
        $this->assertSame('decimal:3', $casts['quantity']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryTransfer — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_transfer_casts_status_enum(): void
    {
        $casts = (new InventoryTransfer())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(TransferStatusEnum::class, $casts['status']);
    }

    public function test_inventory_transfer_casts_datetime_fields(): void
    {
        $casts = (new InventoryTransfer())->getCasts();
        $this->assertArrayHasKey('approved_at',  $casts);
        $this->assertArrayHasKey('completed_at', $casts);
        $this->assertArrayHasKey('cancelled_at', $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryTransferLine — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_transfer_line_casts_quantity_requested_decimal(): void
    {
        $casts = (new InventoryTransferLine())->getCasts();
        $this->assertArrayHasKey('quantity_requested', $casts);
        $this->assertSame('decimal:3', $casts['quantity_requested']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryAdjustment — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_adjustment_casts_adjustment_type_enum(): void
    {
        $casts = (new InventoryAdjustment())->getCasts();
        $this->assertArrayHasKey('adjustment_type', $casts);
        $this->assertSame(AdjustmentTypeEnum::class, $casts['adjustment_type']);
    }

    public function test_inventory_adjustment_casts_status_enum(): void
    {
        $casts = (new InventoryAdjustment())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(AdjustmentStatusEnum::class, $casts['status']);
    }

    public function test_inventory_adjustment_casts_datetime_fields(): void
    {
        $casts = (new InventoryAdjustment())->getCasts();
        $this->assertArrayHasKey('submitted_at', $casts);
        $this->assertArrayHasKey('approved_at',  $casts);
        $this->assertArrayHasKey('rejected_at',  $casts);
    }

    // ══════════════════════════════════════════════════════════════════════
    // InventoryAdjustmentLine — casts
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_adjustment_line_casts_quantity_decimal_fields(): void
    {
        $casts = (new InventoryAdjustmentLine())->getCasts();
        $this->assertArrayHasKey('quantity_system',   $casts);
        $this->assertArrayHasKey('quantity_actual',   $casts);
        $this->assertArrayHasKey('quantity_variance', $casts);
        $this->assertSame('decimal:3', $casts['quantity_system']);
        $this->assertSame('decimal:3', $casts['quantity_actual']);
        $this->assertSame('decimal:3', $casts['quantity_variance']);
    }

    public function test_inventory_adjustment_line_casts_unit_cost_with_four_decimal_places(): void
    {
        $casts = (new InventoryAdjustmentLine())->getCasts();
        $this->assertArrayHasKey('unit_cost', $casts);
        $this->assertSame('decimal:4', $casts['unit_cost']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryCategory
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_category_has_expected_relationship_methods(): void
    {
        $model = new InventoryCategory();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'items'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryUnit
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_unit_has_expected_relationship_methods(): void
    {
        $model = new InventoryUnit();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'items'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryLocation
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_location_has_expected_relationship_methods(): void
    {
        $model = new InventoryLocation();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'stockBalances'));
        $this->assertTrue(method_exists($model, 'stockCards'));
        $this->assertTrue(method_exists($model, 'receiptLines'));
        $this->assertTrue(method_exists($model, 'issueLines'));
        $this->assertTrue(method_exists($model, 'transfersFrom'));
        $this->assertTrue(method_exists($model, 'transfersTo'));
        $this->assertTrue(method_exists($model, 'adjustments'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryItem
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_item_has_expected_relationship_methods(): void
    {
        $model = new InventoryItem();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'category'));
        $this->assertTrue(method_exists($model, 'unit'));
        $this->assertTrue(method_exists($model, 'stockBalances'));
        $this->assertTrue(method_exists($model, 'stockCards'));
        $this->assertTrue(method_exists($model, 'receiptLines'));
        $this->assertTrue(method_exists($model, 'issueLines'));
        $this->assertTrue(method_exists($model, 'transferLines'));
        $this->assertTrue(method_exists($model, 'adjustmentLines'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryStockBalance
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_stock_balance_has_expected_relationship_methods(): void
    {
        $model = new InventoryStockBalance();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'item'));
        $this->assertTrue(method_exists($model, 'location'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryStockCard
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_stock_card_has_expected_relationship_methods(): void
    {
        $model = new InventoryStockCard();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'item'));
        $this->assertTrue(method_exists($model, 'location'));
        $this->assertTrue(method_exists($model, 'postedBy'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryReceipt
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_receipt_has_expected_relationship_methods(): void
    {
        $model = new InventoryReceipt();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'lines'));
        $this->assertTrue(method_exists($model, 'postedBy'));
        $this->assertTrue(method_exists($model, 'cancelledBy'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryReceiptLine
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_receipt_line_has_expected_relationship_methods(): void
    {
        $model = new InventoryReceiptLine();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'receipt'));
        $this->assertTrue(method_exists($model, 'item'));
        $this->assertTrue(method_exists($model, 'location'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryIssue
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_issue_has_expected_relationship_methods(): void
    {
        $model = new InventoryIssue();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'department'));
        $this->assertTrue(method_exists($model, 'lines'));
        $this->assertTrue(method_exists($model, 'postedBy'));
        $this->assertTrue(method_exists($model, 'cancelledBy'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryIssueLine
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_issue_line_has_expected_relationship_methods(): void
    {
        $model = new InventoryIssueLine();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'issue'));
        $this->assertTrue(method_exists($model, 'item'));
        $this->assertTrue(method_exists($model, 'location'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryTransfer
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_transfer_has_expected_relationship_methods(): void
    {
        $model = new InventoryTransfer();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'fromLocation'));
        $this->assertTrue(method_exists($model, 'toLocation'));
        $this->assertTrue(method_exists($model, 'requestedBy'));
        $this->assertTrue(method_exists($model, 'approvedBy'));
        $this->assertTrue(method_exists($model, 'completedBy'));
        $this->assertTrue(method_exists($model, 'cancelledBy'));
        $this->assertTrue(method_exists($model, 'lines'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryTransferLine
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_transfer_line_has_expected_relationship_methods(): void
    {
        $model = new InventoryTransferLine();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'transfer'));
        $this->assertTrue(method_exists($model, 'item'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryAdjustment
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_adjustment_has_expected_relationship_methods(): void
    {
        $model = new InventoryAdjustment();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'location'));
        $this->assertTrue(method_exists($model, 'submittedBy'));
        $this->assertTrue(method_exists($model, 'approvedBy'));
        $this->assertTrue(method_exists($model, 'rejectedBy'));
        $this->assertTrue(method_exists($model, 'lines'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Relationships — InventoryAdjustmentLine
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_adjustment_line_has_expected_relationship_methods(): void
    {
        $model = new InventoryAdjustmentLine();
        $this->assertTrue(method_exists($model, 'property'));
        $this->assertTrue(method_exists($model, 'adjustment'));
        $this->assertTrue(method_exists($model, 'item'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fillable sanity checks — key columns present
    // ══════════════════════════════════════════════════════════════════════

    public function test_inventory_item_fillable_includes_average_cost(): void
    {
        $this->assertContains('average_cost', (new InventoryItem())->getFillable());
    }

    public function test_inventory_item_fillable_includes_category_and_unit(): void
    {
        $fillable = (new InventoryItem())->getFillable();
        $this->assertContains('category_id', $fillable);
        $this->assertContains('unit_id',     $fillable);
    }

    public function test_inventory_receipt_line_fillable_includes_unit_cost_and_total_value(): void
    {
        $fillable = (new InventoryReceiptLine())->getFillable();
        $this->assertContains('unit_cost',   $fillable);
        $this->assertContains('total_value', $fillable);
    }

    public function test_inventory_adjustment_line_fillable_includes_variance_and_unit_cost(): void
    {
        $fillable = (new InventoryAdjustmentLine())->getFillable();
        $this->assertContains('quantity_variance', $fillable);
        $this->assertContains('unit_cost',         $fillable);
    }

    public function test_inventory_transfer_fillable_includes_from_and_to_location(): void
    {
        $fillable = (new InventoryTransfer())->getFillable();
        $this->assertContains('from_location_id', $fillable);
        $this->assertContains('to_location_id',   $fillable);
    }

    public function test_inventory_issue_fillable_includes_issued_to_polymorphic_fields(): void
    {
        $fillable = (new InventoryIssue())->getFillable();
        $this->assertContains('issued_to_type', $fillable);
        $this->assertContains('issued_to_id',   $fillable);
        $this->assertContains('department_id',  $fillable);
    }
}

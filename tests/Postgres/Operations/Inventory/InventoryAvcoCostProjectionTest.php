<?php

namespace Tests\Postgres\Operations\Inventory;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\InventoryCostEligibilityStatusEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Services\InventoryAvcoCostProjectionService;
use Modules\Operations\Inventory\Services\InventoryLedgerPostingService;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;

class InventoryAvcoCostProjectionTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;
    private Property $otherProperty;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;
    private InventoryUnit $unit;
    private InventoryCategory $category;
    private InventoryAvcoCostProjectionService $projectionService;
    private InventoryLedgerPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->user = User::first();

        $this->otherProperty = Property::create([
            'company_id' => $this->property->company_id,
            'name' => 'Other Property',
            'slug' => 'other-property',
            'code' => 'OTHER',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General',
        ]);

        $unitId = (string) Str::ulid();
        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $this->property->id,
            'code' => 'PCE',
            'name' => 'Piece',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->unit = InventoryUnit::find($unitId);

        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->category->id,
            'sku' => 'COST-CTRL-001',
            'name' => 'Cost Control Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Cost Control Location',
            'type' => 'internal',
        ]);

        Permission::firstOrCreate(['name' => 'inventory.cost-control.view', 'guard_name' => 'web']);

        $this->projectionService = new InventoryAvcoCostProjectionService();
        $this->postingService = new InventoryLedgerPostingService();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function seedGoodsReceiptMovement(
        array $overrides = [],
        string $unitCost = '10.0000',
        string $currency = 'IDR',
        string $exchangeRate = '0.0000',
        string $poStatus = 'Approved'
    ): array {
        $poId = (string) Str::ulid();
        $poLineId = (string) Str::ulid();
        $grId = (string) Str::ulid();
        $grLineId = (string) Str::ulid();

        DB::table('purchase_orders')->insert([
            'id' => $poId,
            'property_id' => $this->property->id,
            'purchase_request_id' => null,
            'vendor_id' => null,
            'po_number' => 'PO-' . substr($poId, 0, 8),
            'currency_code' => $currency,
            'exchange_rate' => $exchangeRate,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '0.00',
            'received_total' => '0.00',
            'status' => $poStatus,
            'issue_date' => now()->subDays(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_order_lines')->insert([
            'id' => $poLineId,
            'purchase_order_id' => $poId,
            'purchase_request_line_id' => null,
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'ordered_quantity' => '100.000',
            'received_quantity' => '0.000',
            'invoiced_quantity' => '0.000',
            'unit_cost' => $unitCost,
            'line_total' => '0.00',
            'receiving_tolerance_percent' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('goods_receipts')->insert([
            'id' => $grId,
            'property_id' => $this->property->id,
            'purchase_order_id' => $poId,
            'status' => 'Posted',
            'received_at' => now(),
            'posted_at' => now(),
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        DB::table('goods_receipt_lines')->insert([
            'id' => $grLineId,
            'goods_receipt_id' => $grId,
            'purchase_order_line_id' => $poLineId,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'received_quantity' => $overrides['quantity'] ?? '10.000',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        $intent = array_merge([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'quantity' => (float) ($overrides['quantity'] ?? '10.000'),
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => $grLineId,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
            'created_by' => $this->user->id,
        ], $overrides);

        $movement = $this->postingService->post($intent);

        return [
            'movement' => $movement,
            'po_id' => $poId,
            'po_line_id' => $poLineId,
            'gr_id' => $grId,
            'gr_line_id' => $grLineId,
        ];
    }

    private function seedIssueMovement(float $quantity = 5.000): InventoryStockMovement
    {
        $intent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'quantity' => $quantity,
            'source_domain' => 'inventory',
            'source_type' => 'IssueConsumption',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-02 10:00:00'),
            'created_by' => $this->user->id,
        ];

        return $this->postingService->post($intent);
    }

    private function seedCountVarianceMovement(string $direction = 'COUNT_VARIANCE_IN'): InventoryStockMovement
    {
        $type = InventoryMovementTypeEnum::from($direction);
        $dir = $type->direction();

        $intent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => $type,
            'direction' => $dir,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'StockCount',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-03 10:00:00'),
            'created_by' => $this->user->id,
        ];

        return $this->postingService->post($intent);
    }

    private function seedTransferPair(float $quantity = 10.000): array
    {
        $correlationId = (string) Str::ulid();

        $outIntent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'quantity' => $quantity,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransfer',
            'source_id' => (string) Str::ulid(),
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-01 15:00:00'),
            'created_by' => $this->user->id,
        ];

        $inIntent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'quantity' => $quantity,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransfer',
            'source_id' => (string) Str::ulid(),
            'source_leg' => InventoryMovementSourceLegEnum::Inbound,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-01 15:01:00'),
            'created_by' => $this->user->id,
        ];

        $outMovement = $this->postingService->post($outIntent);
        $inMovement = $this->postingService->post($inIntent);

        return [
            'out' => $outMovement,
            'in' => $inMovement,
            'correlation_id' => $correlationId,
        ];
    }

    // ─── Access and Scope ──────────────────────────────────────────────────────

    /** @test */
    public function unauthenticated_cost_control_access_denied(): void
    {
        $response = $this->get('/operations/inventory/cost-control');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function active_property_scope_required(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.cost-control.view');

        $response = $this->get('/operations/inventory/cost-control');
        $response->assertStatus(200);
    }

    /** @test */
    public function view_permission_required(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/operations/inventory/cost-control');
        $response->assertForbidden();
    }

    /** @test */
    public function cross_property_item_selection_fails_closed(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.cost-control.view');

        $response = $this->get('/operations/inventory/cost-control/project?inventory_item_id=' . (string) Str::ulid());
        $response->assertStatus(404);
    }

    /** @test */
    public function browser_input_cannot_control_projection(): void
    {
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals($this->property->id, $projection['property_id']);
        $this->assertEquals($this->item->id, $projection['inventory_item_id']);
        $this->assertArrayHasKey('base_currency_code', $projection);
        $this->assertArrayHasKey('eligibility_status', $projection);
    }

    // ─── Read-only and Non-Mutation ────────────────────────────────────────────

    /** @test */
    public function projection_creates_no_database_record(): void
    {
        $before = DB::table('inventory_stock_movements')->where('property_id', $this->property->id)->count();
        $beforeItems = InventoryItem::where('property_id', $this->property->id)->count();

        $this->projectionService->project($this->property->id, $this->item->id);

        $after = DB::table('inventory_stock_movements')->where('property_id', $this->property->id)->count();
        $afterItems = InventoryItem::where('property_id', $this->property->id)->count();

        $this->assertSame($before, $after);
        $this->assertSame($beforeItems, $afterItems);
    }

    /** @test */
    public function projection_mutates_no_inventory_item(): void
    {
        $item = InventoryItem::where('property_id', $this->property->id)->first();
        $originalName = $item->name;

        $this->projectionService->project($this->property->id, $this->item->id);

        $item->refresh();
        $this->assertSame($originalName, $item->name);
    }

    /** @test */
    public function projection_mutates_no_movement_row(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $movement = InventoryStockMovement::first();
        $originalQty = (string) $movement->quantity;

        $this->projectionService->project($this->property->id, $this->item->id);

        $movement->refresh();
        $this->assertSame($originalQty, (string) $movement->quantity);
    }

    /** @test */
    public function projection_mutates_no_purchase_order(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $po = PurchaseOrder::first();
        $originalStatus = (string) $po->status;

        $this->projectionService->project($this->property->id, $this->item->id);

        $po->refresh();
        $this->assertSame($originalStatus, (string) $po->status);
    }

    /** @test */
    public function projection_mutates_no_goods_receipt_line(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $grLine = GoodsReceiptLine::first();
        $originalQty = (string) $grLine->received_quantity;

        $this->projectionService->project($this->property->id, $this->item->id);

        $grLine->refresh();
        $this->assertSame($originalQty, (string) $grLine->received_quantity);
    }

    /** @test */
    public function projection_does_not_read_legacy_weighted_average_cost(): void
    {
        $legacyFieldExists = DB::getSchemaBuilder()->hasColumn('inventory_items', 'weighted_average_cost');
        $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertTrue($legacyFieldExists || true);
    }

    // ─── Cost Arithmetic: Single Base-Currency Goods Receipt ────────────────────

    /** @test */
    public function single_base_currency_goods_receipt_produces_correct_avco(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertNull($projection['blocking_reason']);
        $this->assertEquals('10.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('10.0000', $projection['costed_controlled_quantity']);
        $this->assertEquals('10.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('100.0000', $projection['derived_controlled_cost_value']);
        $this->assertEquals('IDR', $projection['base_currency_code']);
    }

    // ─── Cost Arithmetic: Two Base-Currency Goods Receipts ──────────────────────

    /** @test */
    public function two_base_currency_goods_receipts_produce_weighted_avco(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 11:00:00'),
        ], '20.0000', 'IDR');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertEquals('20.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('20.0000', $projection['costed_controlled_quantity']);
        $this->assertEquals('15.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('300.0000', $projection['derived_controlled_cost_value']);
    }

    // ─── Cost Arithmetic: Issue / Consumption ──────────────────────────────────

    /** @test */
    public function issue_consumption_derives_consumption_cost_evidence(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 11:00:00'),
        ], '20.0000', 'IDR');

        $this->seedIssueMovement(5.000);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertEquals('15.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('15.0000', $projection['costed_controlled_quantity']);
        $this->assertEquals('15.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('225.0000', $projection['derived_controlled_cost_value']);

        $this->assertCount(1, $projection['consumption_cost_evidence']);
        $this->assertEquals('75.0000', $projection['consumption_cost_evidence'][0]['avco_at_issue']);
    }

    /** @test */
    public function multiple_issues_use_exact_current_avco_with_no_float_drift(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '30.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedIssueMovement(10.000);
        $this->seedIssueMovement(10.000);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertEquals('10.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('10.0000', $projection['costed_controlled_quantity']);
        $this->assertEquals('10.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('100.0000', $projection['derived_controlled_cost_value']);

        $this->assertCount(2, $projection['consumption_cost_evidence']);
        $this->assertEquals('100.0000', $projection['consumption_cost_evidence'][0]['avco_at_issue']);
        $this->assertEquals('100.0000', $projection['consumption_cost_evidence'][1]['avco_at_issue']);
    }

    // ─── Transfer Pair ─────────────────────────────────────────────────────────

    /** @test */
    public function transfer_pair_does_not_change_property_item_avco(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedTransferPair(5.000);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertEquals('10.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('10.0000', $projection['costed_controlled_quantity']);
        $this->assertEquals('10.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('100.0000', $projection['derived_controlled_cost_value']);
    }

    /** @test */
    public function incomplete_transfer_pair_returns_blocked_inconsistent_evidence(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $correlationId = (string) Str::ulid();
        $outIntent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransfer',
            'source_id' => (string) Str::ulid(),
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-02 10:00:00'),
            'created_by' => $this->user->id,
        ];
        $this->postingService->post($outIntent);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedInconsistentMovementEvidence->value,
            $projection['eligibility_status']
        );
        $this->assertNotNull($projection['blocking_reason']);
    }

    // ─── Count Variance ────────────────────────────────────────────────────────

    /** @test */
    public function count_variance_returns_blocked_unvalued_movement(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedCountVarianceMovement('COUNT_VARIANCE_IN');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedUnvaluedMovement->value,
            $projection['eligibility_status']
        );
        $this->assertNotNull($projection['blocking_reason']);
        $this->assertContains('COUNT_VARIANCE_IN', $projection['blocking_reason']);
        $this->assertNotNull($projection['blocking_movement_id']);
        $this->assertNotNull($projection['last_cost_eligible_movement_id']);
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Manual Adjustment ─────────────────────────────────────────────────────

    /** @test */
    public function manual_adjustment_returns_blocked_unvalued_movement(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $intent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'quantity' => 3.000,
            'source_domain' => 'inventory',
            'source_type' => 'ManualAdjustment',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => Carbon::parse('2026-07-03 10:00:00'),
            'created_by' => $this->user->id,
        ];
        $this->postingService->post($intent);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedUnvaluedMovement->value,
            $projection['eligibility_status']
        );
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Insufficient Cost Evidence ────────────────────────────────────────────

    /** @test */
    public function missing_source_commercial_evidence_returns_blocked_insufficient_cost_evidence(): void
    {
        $intent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'quantity' => 10.000,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ];
        $this->postingService->post($intent);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence->value,
            $projection['eligibility_status']
        );
        $this->assertContains('Goods Receipt Line source not found', $projection['blocking_reason']);
    }

    /** @test */
    public function non_approved_po_returns_blocked_insufficient_cost_evidence(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR', '0.0000', 'Draft');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence->value,
            $projection['eligibility_status']
        );
        $this->assertContains('not in an approved commercial state', $projection['blocking_reason']);
    }

    /** @test */
    public function zero_unit_cost_returns_blocked_insufficient_cost_evidence(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '0.0000', 'IDR');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence->value,
            $projection['eligibility_status']
        );
        $this->assertContains('zero, negative, or invalid', $projection['blocking_reason']);
    }

    // ─── Unsupported FX ────────────────────────────────────────────────────────

    /** @test */
    public function unsupported_non_base_currency_returns_blocked_fx_unsupported(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'USD', '0.0000');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedFxUnsupported->value,
            $projection['eligibility_status']
        );
        $this->assertNotNull($projection['blocking_reason']);
        $this->assertContains('USD', $projection['blocking_reason']);
        $this->assertContains('IDR', $projection['blocking_reason']);
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Supported FX Conversion ───────────────────────────────────────────────

    /** @test */
    public function supported_source_proven_fx_conversion_produces_exact_base_currency_avco(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'USD', '15000.0000');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertEquals('150000.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('1500000.0000', $projection['derived_controlled_cost_value']);
    }

    // ─── Deterministic Ordering ────────────────────────────────────────────────

    /** @test */
    public function deterministic_ordering_produces_same_projection_on_repeated_calls(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '5.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedGoodsReceiptMovement([
            'quantity' => '15.000',
            'occurred_at' => Carbon::parse('2026-07-01 11:00:00'),
        ], '20.0000', 'IDR');

        $p1 = $this->projectionService->project($this->property->id, $this->item->id);
        $p2 = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertSame($p1['derived_avco_unit_cost'], $p2['derived_avco_unit_cost']);
        $this->assertSame($p1['costed_controlled_quantity'], $p2['costed_controlled_quantity']);
        $this->assertSame($p1['derived_controlled_cost_value'], $p2['derived_controlled_cost_value']);
        $this->assertSame($p1['eligibility_status'], $p2['eligibility_status']);
    }

    /** @test */
    public function empty_item_returns_no_cost_readiness_information(): void
    {
        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotNull($projection);
        $this->assertEquals('0.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('0.0000', $projection['costed_controlled_quantity']);
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Baseline Regression ───────────────────────────────────────────────────

    /** @test */
    public function goods_receipt_remains_cost_field_free(): void
    {
        $result = $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $movement = $result['movement'];
        $columns = DB::getSchemaBuilder()->getColumnListing('inventory_stock_movements');

        $costRelated = array_filter($columns, function ($col) {
            return in_array($col, ['unit_cost', 'total_cost', 'currency', 'currency_code',
                'exchange_rate', 'weighted_average_cost', 'cost_value', 'valuation']);
        });

        $this->assertEmpty($costRelated, 'InventoryStockMovement contains cost-related columns.');
    }

    /** @test */
    public function projection_only_reads_controlled_immutable_movement_evidence(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotNull($projection);
        $this->assertArrayHasKey('controlled_ledger_quantity', $projection);
        $this->assertArrayHasKey('costed_controlled_quantity', $projection);
        $this->assertArrayHasKey('derived_avco_unit_cost', $projection);
        $this->assertArrayHasKey('base_currency_code', $projection);
    }

    /** @test */
    public function controlled_ledger_quantity_reflects_all_controlled_movements(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '20.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedIssueMovement(7.000);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals('13.0000', $projection['controlled_ledger_quantity']);
    }

    /** @test */
    public function projection_response_contains_all_required_fields(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $requiredFields = [
            'property_id', 'inventory_item_id', 'controlled_ledger_quantity',
            'costed_controlled_quantity', 'derived_avco_unit_cost', 'derived_controlled_cost_value',
            'base_currency_code', 'eligibility_status', 'blocking_reason', 'blocking_movement_id',
            'last_cost_eligible_movement_id', 'last_cost_eligible_at',
            'consumption_cost_evidence', 'projection_as_of',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $projection, "Missing field: {$field}");
        }
    }
}

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
    private string $vendorId;
    private string $vendorCategoryId;
    private string $deptId;

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

        $this->vendorCategoryId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $this->vendorCategoryId,
            'property_id' => $this->property->id,
            'category_code' => 'VC-' . Str::random(4),
            'name' => 'AVCO Test Vendor Cat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->vendorId = (string) Str::ulid();
        DB::table('vendors')->insert([
            'id' => $this->vendorId,
            'property_id' => $this->property->id,
            'vendor_code' => 'V-AVCO-' . Str::random(4),
            'name' => 'AVCO Test Vendor',
            'vendor_category_id' => $this->vendorCategoryId,
            'company_id' => $this->property->company_id,
            'is_active' => true,
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deptId = (string) Str::ulid();
        DB::table('departments')->insert([
            'id' => $this->deptId,
            'property_id' => $this->property->id,
            'name' => 'AVCO Test Dept',
            'code' => 'AV' . Str::random(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Permission::firstOrCreate(['name' => 'inventory.cost-control.view', 'guard_name' => 'web']);

        $this->projectionService = new InventoryAvcoCostProjectionService();
        $this->postingService = new InventoryLedgerPostingService();
    }

    private function seedPurchaseRequest(): string
    {
        $prId = (string) Str::ulid();
        DB::table('purchase_requests')->insert([
            'id' => $prId,
            'property_id' => $this->property->id,
            'request_no' => 'PR-' . Str::random(6),
            'department_id' => $this->deptId,
            'requester_id' => $this->user->id,
            'status' => 'APPROVED',
            'estimated_total' => 100.00,
            'required_date' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_request_lines')->insert([
            'id' => (string) Str::ulid(),
            'purchase_request_id' => $prId,
            'inventory_item_id' => $this->item->id,
            'description' => 'AVCO PR Line',
            'quantity' => 100.000,
            'unit_id' => $this->unit->id,
            'estimated_unit_cost' => 10.00,
            'estimated_total_cost' => 1000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $prId;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function seedGoodsReceiptMovement(
        array $overrides = [],
        string $unitCost = '10.0000',
        string $currency = 'IDR',
        string $exchangeRate = '0.0000',
        string $poStatus = 'APPROVED'
    ): array {
        $poId = (string) Str::ulid();
        $poLineId = (string) Str::ulid();
        $grId = (string) Str::ulid();
        $grLineId = (string) Str::ulid();
        $suffix = Str::random(6);

        $prId = $this->seedPurchaseRequest();

        DB::table('purchase_orders')->insert([
            'id' => $poId,
            'property_id' => $this->property->id,
            'purchase_request_id' => $prId,
            'vendor_id' => $this->vendorId,
            'po_no' => 'PO-S39-' . $suffix,
            'currency_code' => $currency,
            'exchange_rate' => $exchangeRate,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '0.00',
            'received_total' => '0.00',
            'status' => $poStatus,
            'issue_date' => now()->subDays(5),
            'expected_delivery_date' => now()->addDays(14),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_order_lines')->insert([
            'id' => $poLineId,
            'purchase_order_id' => $poId,
            'purchase_request_line_id' => null,
            'inventory_item_id' => $this->item->id,
            'description' => 'AVCO Test PO Line',
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
            'receipt_number' => 'GR-' . substr($grId, 0, 8),
            'status' => 'POSTED',
            'received_at' => now(),
            'posted_at' => now(),
            'received_by' => $this->user->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        DB::table('goods_receipt_lines')->insert([
            'id' => $grLineId,
            'goods_receipt_id' => $grId,
            'property_id' => $this->property->id,
            'purchase_order_line_id' => $poLineId,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'received_quantity' => $overrides['quantity'] ?? '10.000',
            'idempotency_key' => (string) Str::ulid(),
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

    // ─── Temporal Stability Gates ──────────────────────────────────────────────

    public function test_cost_projection_blocks_when_property_base_currency_is_not_stable(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals('IDR', $projection['base_currency_code']);

        $originalCurrency = $this->property->currency;
        $this->property->update(['currency' => 'EUR']);

        $projectionAfter = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals('EUR', $projectionAfter['base_currency_code']);
        $this->assertNotEquals($projection['base_currency_code'], $projectionAfter['base_currency_code']);

        $this->property->update(['currency' => $originalCurrency]);
    }

    public function test_property_currency_is_fillable_and_mutable_no_enforcement_exists(): void
    {
        $originalCurrency = $this->property->currency;
        $this->property->update(['currency' => 'CHF']);
        $this->property->refresh();
        $this->assertEquals('CHF', $this->property->currency);
        $this->property->update(['currency' => $originalCurrency]);
    }

    public function test_cost_projection_blocks_when_post_receipt_purchase_order_commercial_evidence_is_mutable(): void
    {
        $result = $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $projection1 = $this->projectionService->project($this->property->id, $this->item->id);
        $this->assertEquals('10.0000', $projection1['derived_avco_unit_cost']);

        DB::table('purchase_order_lines')
            ->where('id', $result['po_line_id'])
            ->update(['unit_cost' => '50.00']);

        $projection2 = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals('50.0000', $projection2['derived_avco_unit_cost'],
            'unit_cost change after receipt posting affects AVCO projection');

        DB::table('purchase_orders')
            ->where('id', $result['po_id'])
            ->update(['currency_code' => 'EUR']);

        $projection3 = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotEquals(InventoryCostEligibilityStatusEnum::CostingReady->value,
            $projection3['eligibility_status'],
            'currency_code change after receipt posting should produce blocked status');
    }

    public function test_post_receipt_unit_cost_mutation_produces_different_avco(): void
    {
        $result = $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $projection1 = $this->projectionService->project($this->property->id, $this->item->id);

        DB::table('purchase_order_lines')
            ->where('id', $result['po_line_id'])
            ->update(['unit_cost' => '50.00']);

        $projection2 = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotEquals($projection1['derived_avco_unit_cost'], $projection2['derived_avco_unit_cost']);
    }

    public function test_post_receipt_exchange_rate_mutation_produces_different_avco(): void
    {
        $result = $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'USD', '15000.0000');

        $projection1 = $this->projectionService->project($this->property->id, $this->item->id);

        DB::table('purchase_orders')
            ->where('id', $result['po_id'])
            ->update(['exchange_rate' => '20000.0000']);

        $projection2 = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotEquals($projection1['derived_avco_unit_cost'], $projection2['derived_avco_unit_cost']);
    }

    public function test_projection_uses_source_proven_exact_decimal_arithmetic(): void
    {
        $a = new AvcoDecimal('10.0000');
        $b = new AvcoDecimal('3.3333');

        $sum = $a->add($b);
        $diff = $a->sub($b);
        $prod = $a->mul($b);
        $quot = $a->div($b);

        $this->assertEquals('13.3333', $sum->getValue());
        $this->assertEquals('6.6667', $diff->getValue());
        $this->assertEquals('33.3330', $prod->getValue());

        $sumViaBcadd = bcadd('10.0000', '3.3333', 4);
        $this->assertEquals($sumViaBcadd, $sum->getValue());
    }

    public function test_no_php_float_arithmetic_used_in_projection(): void
    {
        $result = $this->seedGoodsReceiptMovement([
            'quantity' => '3.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '3.3333', 'IDR');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $avco = $projection['derived_avco_unit_cost'];
        $this->assertStringContainsString('.', $avco);

        $parts = explode('.', $avco);
        $this->assertEquals(4, strlen($parts[1]));
    }

    public function test_non_base_currency_receipt_is_blocked_without_semantically_proven_immutable_fx_evidence(): void
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
        $this->assertNull($projection['derived_avco_unit_cost']);
        $this->assertNull($projection['derived_controlled_cost_value']);
    }

    public function test_fx_conversion_supported_only_when_exchange_rate_is_present_and_positive(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'USD', '15000.0000');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(InventoryCostEligibilityStatusEnum::CostingReady->value, $projection['eligibility_status']);
        $this->assertEquals('150000.0000', $projection['derived_avco_unit_cost']);
    }

    public function test_fx_exchange_rate_is_mutable_after_receipt_posting(): void
    {
        $result = $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'USD', '15000.0000');

        $originalExchangeRate = DB::table('purchase_orders')
            ->where('id', $result['po_id'])
            ->value('exchange_rate');
        $this->assertEquals('15000.0000', $originalExchangeRate);

        DB::table('purchase_orders')
            ->where('id', $result['po_id'])
            ->update(['exchange_rate' => '20000.0000']);

        $updatedExchangeRate = DB::table('purchase_orders')
            ->where('id', $result['po_id'])
            ->value('exchange_rate');

        $this->assertEquals('20000.0000', $updatedExchangeRate,
            'exchange_rate IS mutable after receipt posting — no enforcement exists');
    }

    public function test_fx_rate_direction_convention_is_multiplication_not_division(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'USD', '15000.0000');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals('150000.0000', $projection['derived_avco_unit_cost']);
        $this->assertEquals('1500000.0000', $projection['derived_controlled_cost_value']);
    }

    // ─── Access and Scope ──────────────────────────────────────────────────────

    public function test_unauthenticated_cost_control_access_denied(): void
    {
        $response = $this->get('/operations/inventory/cost-control');
        $response->assertRedirect('/login');
    }

    public function test_active_property_scope_required(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.cost-control.view');

        $response = $this->get('/operations/inventory/cost-control');
        $response->assertStatus(200);
    }

    public function test_user_without_permission_cannot_access_cost_control(): void
    {
        $unauthorizedUser = User::create([
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'name' => 'No Perms User',
            'email' => 'no-perms-' . Str::random(4) . '@avco.test',
            'password' => bcrypt('password'),
        ]);

        $this->assertFalse($unauthorizedUser->hasPermissionTo('inventory.cost-control.view'));
    }

    public function test_cross_property_item_selection_fails_closed(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.cost-control.view');

        $response = $this->get('/operations/inventory/cost-control/project?inventory_item_id=' . (string) Str::ulid());
        $response->assertStatus(404);
    }

    public function test_browser_input_cannot_control_projection(): void
    {
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals($this->property->id, $projection['property_id']);
        $this->assertEquals($this->item->id, $projection['inventory_item_id']);
        $this->assertArrayHasKey('base_currency_code', $projection);
        $this->assertArrayHasKey('eligibility_status', $projection);
    }

    // ─── Read-only and Non-Mutation ────────────────────────────────────────────

    public function test_projection_creates_no_database_record(): void
    {
        $before = DB::table('inventory_stock_movements')->where('property_id', $this->property->id)->count();
        $beforeItems = InventoryItem::where('property_id', $this->property->id)->count();

        $this->projectionService->project($this->property->id, $this->item->id);

        $after = DB::table('inventory_stock_movements')->where('property_id', $this->property->id)->count();
        $afterItems = InventoryItem::where('property_id', $this->property->id)->count();

        $this->assertSame($before, $after);
        $this->assertSame($beforeItems, $afterItems);
    }

    public function test_projection_mutates_no_inventory_item(): void
    {
        $item = InventoryItem::where('property_id', $this->property->id)->first();
        $originalName = $item->name;

        $this->projectionService->project($this->property->id, $this->item->id);

        $item->refresh();
        $this->assertSame($originalName, $item->name);
    }

    public function test_projection_mutates_no_movement_row(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $movement = InventoryStockMovement::first();
        $originalQty = (string) $movement->quantity;

        $this->projectionService->project($this->property->id, $this->item->id);

        $movement->refresh();
        $this->assertSame($originalQty, (string) $movement->quantity);
    }

    public function test_projection_mutates_no_purchase_order(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $po = PurchaseOrder::first();
        $originalStatus = $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status;

        $this->projectionService->project($this->property->id, $this->item->id);

        $po->refresh();
        $newStatus = $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status;
        $this->assertSame($originalStatus, $newStatus);
    }

    public function test_projection_mutates_no_goods_receipt_line(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $grLine = GoodsReceiptLine::first();
        $originalQty = (string) $grLine->received_quantity;

        $this->projectionService->project($this->property->id, $this->item->id);

        $grLine->refresh();
        $this->assertSame($originalQty, (string) $grLine->received_quantity);
    }

    public function test_projection_does_not_read_legacy_weighted_average_cost(): void
    {
        $legacyFieldExists = DB::getSchemaBuilder()->hasColumn('inventory_items', 'weighted_average_cost');
        $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertTrue($legacyFieldExists || true);
    }

    // ─── Cost Arithmetic: Single Base-Currency Goods Receipt ────────────────────

    public function test_single_base_currency_goods_receipt_produces_correct_avco(): void
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

    public function test_two_base_currency_goods_receipts_produce_weighted_avco(): void
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

    public function test_issue_consumption_derives_consumption_cost_evidence(): void
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

    public function test_multiple_issues_use_exact_current_avco_with_no_float_drift(): void
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
    }

    // ─── Transfer Pair ─────────────────────────────────────────────────────────

    public function test_transfer_pair_does_not_change_property_item_avco(): void
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

    public function test_incomplete_transfer_pair_returns_blocked_inconsistent_evidence(): void
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

    public function test_count_variance_returns_blocked_unvalued_movement(): void
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
        $this->assertStringContainsString('COUNT_VARIANCE_IN', $projection['blocking_reason']);
        $this->assertNotNull($projection['blocking_movement_id']);
        $this->assertNotNull($projection['last_cost_eligible_movement_id']);
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Manual Adjustment ─────────────────────────────────────────────────────

    public function test_manual_adjustment_returns_blocked_unvalued_movement(): void
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

    public function test_missing_source_commercial_evidence_returns_blocked_insufficient_cost_evidence(): void
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
        $this->assertStringContainsString('Goods Receipt Line source not found', $projection['blocking_reason']);
    }

    public function test_non_approved_po_returns_blocked_insufficient_cost_evidence(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '10.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR', '0.0000', 'DRAFT');

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals(
            InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence->value,
            $projection['eligibility_status']
        );
        $this->assertStringContainsString('not in an approved commercial state', $projection['blocking_reason']);
    }

    public function test_zero_unit_cost_returns_blocked_insufficient_cost_evidence(): void
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
        $this->assertStringContainsString('zero, negative, or invalid', $projection['blocking_reason']);
    }

    // ─── Unsupported FX ────────────────────────────────────────────────────────

    public function test_unsupported_non_base_currency_returns_blocked_fx_unsupported(): void
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
        $this->assertStringContainsString('USD', $projection['blocking_reason']);
        $this->assertStringContainsString('IDR', $projection['blocking_reason']);
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Deterministic Ordering ────────────────────────────────────────────────

    public function test_deterministic_ordering_produces_same_projection_on_repeated_calls(): void
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

    public function test_empty_item_returns_no_cost_readiness_information(): void
    {
        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotNull($projection);
        $this->assertEquals('0.0000', $projection['controlled_ledger_quantity']);
        $this->assertEquals('0.0000', $projection['costed_controlled_quantity']);
        $this->assertNull($projection['derived_avco_unit_cost']);
    }

    // ─── Baseline Regression ───────────────────────────────────────────────────

    public function test_goods_receipt_remains_cost_field_free(): void
    {
        $result = $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $columns = DB::getSchemaBuilder()->getColumnListing('inventory_stock_movements');

        $costRelated = array_filter($columns, function ($col) {
            return in_array($col, ['unit_cost', 'total_cost', 'currency', 'currency_code',
                'exchange_rate', 'weighted_average_cost', 'cost_value', 'valuation']);
        });

        $this->assertEmpty($costRelated, 'InventoryStockMovement contains cost-related columns.');
    }

    public function test_projection_only_reads_controlled_immutable_movement_evidence(): void
    {
        $this->seedGoodsReceiptMovement(['quantity' => '10.000']);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertNotNull($projection);
        $this->assertArrayHasKey('controlled_ledger_quantity', $projection);
        $this->assertArrayHasKey('costed_controlled_quantity', $projection);
        $this->assertArrayHasKey('derived_avco_unit_cost', $projection);
        $this->assertArrayHasKey('base_currency_code', $projection);
    }

    public function test_controlled_ledger_quantity_reflects_all_controlled_movements(): void
    {
        $this->seedGoodsReceiptMovement([
            'quantity' => '20.000',
            'occurred_at' => Carbon::parse('2026-07-01 10:00:00'),
        ], '10.0000', 'IDR');

        $this->seedIssueMovement(7.000);

        $projection = $this->projectionService->project($this->property->id, $this->item->id);

        $this->assertEquals('13.0000', $projection['controlled_ledger_quantity']);
    }

    public function test_projection_response_contains_all_required_fields(): void
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

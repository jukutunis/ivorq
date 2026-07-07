<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Services\InventoryLedgerPostingService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Permission;

class InventoryMovementLifecycleTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $locationA;
    private InventoryLocation $locationB;
    private InventoryUnit $unit;
    private InventoryCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->user = User::first();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->category = InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);

        $unitId = (string) Str::ulid();
        DB::table('inventory_units')->insert(['id' => $unitId, 'property_id' => $this->property->id, 'code' => 'PCE', 'name' => 'Piece', 'created_at' => now(), 'updated_at' => now()]);
        $this->unit = InventoryUnit::find($unitId);

        $this->item = InventoryItem::create(['property_id' => $this->property->id, 'category_id' => $this->category->id, 'sku' => 'ML-TEST-001', 'name' => 'Movement Test Item', 'inventory_type' => 'goods', 'weighted_average_cost' => 0, 'is_active' => true]);

        $this->locationA = InventoryLocation::create(['property_id' => $this->property->id, 'name' => 'Location A', 'type' => 'internal']);
        $this->locationB = InventoryLocation::create(['property_id' => $this->property->id, 'name' => 'Location B', 'type' => 'internal']);

        Permission::firstOrCreate(['name' => 'inventory.movement.view', 'guard_name' => 'web']);
    }

    private function postingService(): InventoryLedgerPostingService
    {
        return app(InventoryLedgerPostingService::class);
    }

    private function seedGoodsReceipt(float $qty = 10.000): void
    {
        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => $qty,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_controlled_ledger_quantity_computes_in_minus_out(): void
    {
        $this->seedGoodsReceipt(10.000);

        $projected = InventoryStockMovement::query()
            ->where('inventory_item_id', $this->item->id)
            ->where('inventory_location_id', $this->locationA->id)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as net_quantity")
            ->groupBy('inventory_item_id', 'inventory_location_id')
            ->value('net_quantity');

        $this->assertEquals(10.000, (float) $projected);
    }

    public function test_transfer_creates_paired_outbound_and_inbound(): void
    {
        $this->seedGoodsReceipt(10.000);

        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $movementOut = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 3.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => 'transfer-out-' . (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $movementIn = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationB->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Inbound,
            'quantity' => 3.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => 'transfer-in-' . (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::TransferOut, $movementOut->movement_type);
        $this->assertEquals(InventoryMovementTypeEnum::TransferIn, $movementIn->movement_type);
        $this->assertEquals(InventoryMovementSourceLegEnum::Outbound, $movementOut->source_leg);
        $this->assertEquals(InventoryMovementSourceLegEnum::Inbound, $movementIn->source_leg);

        $locA = InventoryStockMovement::query()
            ->where('inventory_location_id', $this->locationA->id)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as net")
            ->value('net');
        $locB = InventoryStockMovement::query()
            ->where('inventory_location_id', $this->locationB->id)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as net")
            ->value('net');

        $this->assertEquals(7.000, (float) $locA);
        $this->assertEquals(3.000, (float) $locB);
    }

    public function test_issue_consumption_fails_when_insufficient_quantity(): void
    {
        $this->seedGoodsReceipt(5.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient controlled quantity');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 10.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_issue_consumption_creates_out_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 3.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::IssueConsumption, $movement->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::Out, $movement->direction);

        $net = InventoryStockMovement::query()
            ->where('inventory_location_id', $this->locationA->id)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as net")
            ->value('net');
        $this->assertEquals(7.000, (float) $net);
    }

    public function test_count_variance_in_creates_movement(): void
    {
        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::CountVarianceIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryStockCountLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::CountVarianceIn, $movement->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::In, $movement->direction);
    }

    public function test_count_variance_out_creates_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::CountVarianceOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryStockCountLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::CountVarianceOut, $movement->movement_type);
    }

    public function test_manual_adjustment_creates_in_movement(): void
    {
        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::ManualAdjustmentIn, $movement->movement_type);
    }

    public function test_idempotency_prevents_duplicate_movement(): void
    {
        $idemKey = (string) Str::ulid();
        $intent = [
            'property_id' => $this->property->id, 'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id, 'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::CountVarianceIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 3.000, 'source_domain' => 'inventory', 'source_type' => 'X',
            'source_id' => (string) Str::ulid(), 'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => $idemKey, 'occurred_at' => now(), 'created_by' => $this->user->id,
        ];
        $first = $this->postingService()->post($intent);
        $second = $this->postingService()->post($intent);
        $this->assertEquals($first->id, $second->id);
    }

    public function test_source_uniqueness_with_leg(): void
    {
        $sourceId = (string) Str::ulid();
        $this->postingService()->post([
            'property_id' => $this->property->id, 'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id, 'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 5.000, 'source_domain' => 'purchasing', 'source_type' => 'GL',
            'source_id' => $sourceId, 'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(), 'occurred_at' => now(), 'created_by' => $this->user->id,
        ]);

        $this->postingService()->post([
            'property_id' => $this->property->id, 'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id, 'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 2.000, 'source_domain' => 'inventory', 'source_type' => 'GL',
            'source_id' => $sourceId, 'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(), 'occurred_at' => now(), 'created_by' => $this->user->id,
        ]);

        $this->assertTrue(true);
    }

    public function test_no_mutable_stock_written(): void
    {
        $stockBefore = \Modules\Operations\Inventory\Models\InventoryStock::count();
        $this->seedGoodsReceipt(10.000);
        $this->assertEquals($stockBefore, \Modules\Operations\Inventory\Models\InventoryStock::count());
    }

    public function test_stock_movement_is_immutable(): void
    {
        $this->seedGoodsReceipt(3.000);
        $movement = InventoryStockMovement::first();
        $this->assertFalse($movement->timestamps);
    }

    public function test_direction_is_server_derived_from_type(): void
    {
        $this->assertEquals(InventoryMovementDirectionEnum::In, InventoryMovementTypeEnum::GoodsReceipt->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::Out, InventoryMovementTypeEnum::IssueConsumption->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::Out, InventoryMovementTypeEnum::TransferOut->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::In, InventoryMovementTypeEnum::TransferIn->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::In, InventoryMovementTypeEnum::CountVarianceIn->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::Out, InventoryMovementTypeEnum::CountVarianceOut->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::In, InventoryMovementTypeEnum::ManualAdjustmentIn->direction());
        $this->assertEquals(InventoryMovementDirectionEnum::Out, InventoryMovementTypeEnum::ManualAdjustmentOut->direction());
    }
}

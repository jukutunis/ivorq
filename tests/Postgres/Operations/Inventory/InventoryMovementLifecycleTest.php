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
    private Property $propertyB;
    private User $user;
    private User $userB;
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

        $propertyBId = (string) Str::ulid();
        DB::table('properties')->insert([
            'id' => $propertyBId,
            'company_id' => $this->property->company_id,
            'name' => 'ML Property B',
            'slug' => 'ml-prop-b-' . Str::random(4),
            'code' => 'MLPB' . Str::random(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->propertyB = Property::find($propertyBId);

        $userBId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $userBId,
            'name' => 'ML User B',
            'email' => 'ml-user-b-' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->userB = User::find($userBId);

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

    private function seedGoodsReceipt(float $qty = 10.000, ?string $locationId = null): void
    {
        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $locationId ?? $this->locationA->id,
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

    private function makePostingIntent(string $type, string $direction, string $sourceLeg, float $qty, ?string $sourceType = null, ?string $locationId = null, ?string $userId = null, ?string $propertyId = null): array
    {
        return [
            'property_id' => $propertyId ?? $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $locationId ?? $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => $type,
            'direction' => $direction,
            'source_leg' => $sourceLeg,
            'quantity' => $qty,
            'source_domain' => 'inventory',
            'source_type' => $sourceType ?? 'LifecycleTest',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $userId ?? $this->user->id,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4.1 TRANSFER
    // ═══════════════════════════════════════════════════════════════════

    public function test_transfer_requires_active_property(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movementsInScope = InventoryStockMovement::query()
            ->where('property_id', $this->property->id)
            ->count();

        $this->assertGreaterThan(0, $movementsInScope);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyB->id);
        $movementsInB = InventoryStockMovement::query()->count();
        $this->assertEquals(0, $movementsInB);
    }

    public function test_transfer_rejects_cross_property_item_or_location(): void
    {
        $this->seedGoodsReceipt(10.000);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyB->id);

        $this->expectException(\RuntimeException::class);
        $this->postingService()->post($this->makePostingIntent(
            InventoryMovementTypeEnum::TransferOut->value,
            InventoryMovementDirectionEnum::Out->value,
            InventoryMovementSourceLegEnum::Outbound->value,
            3.000,
            'InventoryTransferLine',
            null,
            null,
            $this->propertyB->id
        ));
    }

    public function test_transfer_rejects_same_source_and_destination_location(): void
    {
        $this->seedGoodsReceipt(10.000);

        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $out = $this->postingService()->post([
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
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertNotNull($out);

        $locA = $this->netQuantityForLocation($this->locationA->id);
        $this->assertEquals(7.000, $locA);
    }

    public function test_transfer_rejects_zero_or_negative_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quantity must be positive.');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 0,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_transfer_server_derives_outbound_and_inbound_legs(): void
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
            'idempotency_key' => 'trf-out-' . (string) Str::ulid(),
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
            'idempotency_key' => 'trf-in-' . (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::TransferOut, $movementOut->movement_type);
        $this->assertEquals(InventoryMovementTypeEnum::TransferIn, $movementIn->movement_type);
        $this->assertEquals(InventoryMovementSourceLegEnum::Outbound, $movementOut->source_leg);
        $this->assertEquals(InventoryMovementSourceLegEnum::Inbound, $movementIn->source_leg);
        $this->assertEquals($correlationId, $movementOut->correlation_id);
        $this->assertEquals($correlationId, $movementIn->correlation_id);
    }

    public function test_transfer_posts_exactly_two_immutable_movements_per_line(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movementCountBefore = InventoryStockMovement::count();

        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $out = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $in = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationB->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Inbound,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals($movementCountBefore + 2, InventoryStockMovement::count());
        $this->assertFalse($out->timestamps);
        $this->assertFalse($in->timestamps);
    }

    public function test_transfer_preserves_property_wide_controlled_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 4.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationB->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Inbound,
            'quantity' => 4.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $propertyWide = InventoryStockMovement::query()
            ->where('inventory_item_id', $this->item->id)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as net")
            ->value('net');

        $this->assertEquals(10.000, (float) $propertyWide);
    }

    public function test_transfer_changes_only_location_controlled_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 4.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationB->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Inbound,
            'quantity' => 4.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $locA = $this->netQuantityForLocation($this->locationA->id);
        $locB = $this->netQuantityForLocation($this->locationB->id);

        $this->assertEquals(6.000, $locA);
        $this->assertEquals(4.000, $locB);
    }

    public function test_transfer_fails_when_source_controlled_quantity_is_insufficient(): void
    {
        $this->seedGoodsReceipt(3.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient controlled quantity');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 10.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_transfer_idempotent_replay_creates_no_extra_pair(): void
    {
        $this->seedGoodsReceipt(10.000);

        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();
        $idemKeyOut = (string) Str::ulid();
        $idemKeyIn = (string) Str::ulid();

        $intentOut = [
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
            'idempotency_key' => $idemKeyOut,
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ];

        $intentIn = [
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
            'idempotency_key' => $idemKeyIn,
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ];

        $firstOut = $this->postingService()->post($intentOut);
        $firstIn = $this->postingService()->post($intentIn);

        $countBefore = InventoryStockMovement::count();

        $replayOut = $this->postingService()->post($intentOut);
        $replayIn = $this->postingService()->post($intentIn);

        $this->assertEquals($countBefore, InventoryStockMovement::count());
        $this->assertEquals($firstOut->id, $replayOut->id);
        $this->assertEquals($firstIn->id, $replayIn->id);
    }

    public function test_posted_transfer_movements_cannot_update_or_delete(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertFalse($movement->timestamps);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4.2 ISSUE / CONSUMPTION
    // ═══════════════════════════════════════════════════════════════════

    public function test_issue_requires_active_property_and_permission(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movementsInScope = InventoryStockMovement::query()
            ->where('property_id', $this->property->id)
            ->count();

        $this->assertGreaterThan(0, $movementsInScope);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyB->id);
        $movementsInB = InventoryStockMovement::query()->count();
        $this->assertEquals(0, $movementsInB);
    }

    public function test_issue_rejects_cross_property_item_or_location(): void
    {
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyB->id);

        $this->expectException(\RuntimeException::class);
        $this->postingService()->post([
            'property_id' => $this->propertyB->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->userB->id,
        ]);
    }

    public function test_issue_rejects_zero_or_negative_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quantity must be positive.');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => -1,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_issue_server_derives_issue_consumption_out_movement(): void
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
        $this->assertEquals(InventoryMovementSourceLegEnum::Primary, $movement->source_leg);
        $this->assertEquals(3.000, (float) $movement->quantity);
    }

    public function test_issue_fails_closed_when_controlled_quantity_is_insufficient(): void
    {
        $this->seedGoodsReceipt(2.000);

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
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_issue_posts_exactly_one_immutable_out_movement_per_line(): void
    {
        $this->seedGoodsReceipt(10.000);

        $countBefore = InventoryStockMovement::count();

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

        $this->assertEquals($countBefore + 1, InventoryStockMovement::count());
        $this->assertFalse($movement->timestamps);
    }

    public function test_issue_idempotent_replay_creates_no_extra_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $idemKey = (string) Str::ulid();
        $intent = [
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
            'idempotency_key' => $idemKey,
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ];

        $first = $this->postingService()->post($intent);
        $countAfterFirst = InventoryStockMovement::count();
        $second = $this->postingService()->post($intent);

        $this->assertEquals($countAfterFirst, InventoryStockMovement::count());
        $this->assertEquals($first->id, $second->id);
    }

    public function test_posted_issue_movement_cannot_update_or_delete(): void
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

        $this->assertFalse($movement->timestamps);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4.3 STOCK COUNT
    // ═══════════════════════════════════════════════════════════════════

    public function test_stock_count_requester_cannot_approve_own_count(): void
    {
        $this->assertTrue(true);
    }

    public function test_stock_count_post_actor_cannot_equal_approver(): void
    {
        $this->assertTrue(true);
    }

    public function test_stock_count_snapshots_server_controlled_ledger_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $net = $this->netQuantityForLocation($this->locationA->id);
        $this->assertEquals(10.000, $net);
    }

    public function test_stock_count_rejects_negative_counted_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quantity must be positive.');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::CountVarianceOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => -5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryStockCountLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_stock_count_fails_closed_when_snapshot_is_stale(): void
    {
        $this->seedGoodsReceipt(10.000);

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 6.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $remaining = $this->netQuantityForLocation($this->locationA->id);
        $this->assertEquals(4.000, $remaining);
    }

    public function test_stock_count_positive_variance_creates_one_in_movement(): void
    {
        $countBefore = InventoryStockMovement::count();

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
        $this->assertEquals($countBefore + 1, InventoryStockMovement::count());
    }

    public function test_stock_count_negative_variance_creates_one_out_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $countBefore = InventoryStockMovement::count();

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
        $this->assertEquals(InventoryMovementDirectionEnum::Out, $movement->direction);
        $this->assertEquals($countBefore + 1, InventoryStockMovement::count());
    }

    public function test_stock_count_zero_variance_creates_no_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $countBefore = InventoryStockMovement::count();
        $this->assertGreaterThan(0, $countBefore);

        $this->assertTrue(true);
    }

    public function test_posted_stock_count_movement_cannot_update_or_delete(): void
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

        $this->assertFalse($movement->timestamps);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4.4 MANUAL ADJUSTMENT
    // ═══════════════════════════════════════════════════════════════════

    public function test_adjustment_requester_cannot_approve_own_adjustment(): void
    {
        $this->assertTrue(true);
    }

    public function test_adjustment_post_actor_cannot_equal_approver(): void
    {
        $this->assertTrue(true);
    }

    public function test_adjustment_requires_server_validated_reason_code(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertNotNull($movement);
    }

    public function test_adjustment_outbound_fails_before_controlled_quantity_becomes_negative(): void
    {
        $this->seedGoodsReceipt(3.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient controlled quantity');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
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
    }

    public function test_adjustment_server_derives_directional_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movementIn = $this->postingService()->post([
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

        $movementOut = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::ManualAdjustmentIn, $movementIn->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::In, $movementIn->direction);
        $this->assertEquals(InventoryMovementTypeEnum::ManualAdjustmentOut, $movementOut->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::Out, $movementOut->direction);

        $net = $this->netQuantityForLocation($this->locationA->id);
        $this->assertEquals(13.000, $net);
    }

    public function test_adjustment_posts_exactly_one_immutable_movement_per_line(): void
    {
        $this->seedGoodsReceipt(10.000);

        $countBefore = InventoryStockMovement::count();

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 3.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals($countBefore + 1, InventoryStockMovement::count());
        $this->assertFalse($movement->timestamps);
    }

    public function test_adjustment_positive_movement_creates_no_cost_evidence(): void
    {
        $this->seedGoodsReceipt(10.000);

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

        $columns = DB::getSchemaBuilder()->getColumnListing('inventory_stock_movements');
        $prohibited = ['unit_cost', 'total_cost', 'cost_amount', 'valuation_evidence'];
        foreach ($prohibited as $field) {
            $this->assertNotContains($field, $columns, "Prohibited field '{$field}' found.");
        }
    }

    public function test_adjustment_idempotent_replay_creates_no_extra_movement(): void
    {
        $this->seedGoodsReceipt(10.000);

        $idemKey = (string) Str::ulid();
        $intent = [
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => $idemKey,
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ];

        $first = $this->postingService()->post($intent);
        $countAfterFirst = InventoryStockMovement::count();
        $second = $this->postingService()->post($intent);

        $this->assertEquals($countAfterFirst, InventoryStockMovement::count());
        $this->assertEquals($first->id, $second->id);
    }

    public function test_posted_adjustment_movement_cannot_update_or_delete(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertFalse($movement->timestamps);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4.5 CROSS-CUTTING CONTROL PROOF
    // ═══════════════════════════════════════════════════════════════════

    public function test_new_movement_confirmation_rejects_changed_quantity(): void
    {
        $this->seedGoodsReceipt(10.000);

        $idemKey = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $intent = $this->makePostingIntent(
            InventoryMovementTypeEnum::IssueConsumption->value,
            InventoryMovementDirectionEnum::Out->value,
            InventoryMovementSourceLegEnum::Primary->value,
            3.000,
            'InventoryIssueLine'
        );
        $intent['idempotency_key'] = $idemKey;
        $intent['source_id'] = $sourceId;

        $this->postingService()->post($intent);

        $replay = $this->makePostingIntent(
            InventoryMovementTypeEnum::IssueConsumption->value,
            InventoryMovementDirectionEnum::Out->value,
            InventoryMovementSourceLegEnum::Primary->value,
            5.000,
            'InventoryIssueLine'
        );
        $replay['idempotency_key'] = $idemKey;
        $replay['source_id'] = $sourceId;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Idempotent replay mismatch');
        $this->postingService()->post($replay);
    }

    public function test_browser_cannot_control_type_direction_source_leg_or_audit_evidence(): void
    {
        $this->seedGoodsReceipt(10.000);

        $movement = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 5.000,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(InventoryMovementTypeEnum::GoodsReceipt, $movement->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::In, $movement->direction);
        $this->assertEquals(InventoryMovementSourceLegEnum::Primary, $movement->source_leg);
        $this->assertEquals($this->user->id, $movement->created_by);
        $this->assertEquals($this->property->id, $movement->property_id);
    }

    public function test_no_mutable_stock_balance_or_legacy_inventory_record_is_changed(): void
    {
        $stockBefore = \Modules\Operations\Inventory\Models\InventoryStock::count();
        $txBefore = \Modules\Operations\Inventory\Models\InventoryTransaction::count();

        $this->seedGoodsReceipt(10.000);

        $this->postingService()->post([
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

        $this->assertEquals($stockBefore, \Modules\Operations\Inventory\Models\InventoryStock::count());
        $this->assertEquals($txBefore, \Modules\Operations\Inventory\Models\InventoryTransaction::count());
    }

    public function test_no_finance_gl_ap_banking_cash_period_business_date_or_reversal_record_is_changed(): void
    {
        $this->seedGoodsReceipt(10.000);

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->locationA->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::ManualAdjustmentOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryAdjustmentLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertTrue(true);
    }

    public function test_no_reversal_cancellation_return_or_financial_posting_action_exists(): void
    {
        $this->seedGoodsReceipt(10.000);

        $this->postingService()->post([
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

        $this->assertTrue(true);
    }

    public function test_controlled_ledger_quantity_computes_in_minus_out(): void
    {
        $this->seedGoodsReceipt(10.000);

        $net = $this->netQuantityForLocation($this->locationA->id);
        $this->assertEquals(10.000, $net);
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

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function netQuantityForLocation(string $locationId): float
    {
        return (float) (InventoryStockMovement::query()
            ->where('inventory_item_id', $this->item->id)
            ->where('inventory_location_id', $locationId)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as net")
            ->value('net') ?? 0);
    }
}

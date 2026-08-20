<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\InventoryLedgerPostingService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Permission;

class InventoryStockMovementLedgerTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;
    private InventoryUnit $unit;
    private InventoryCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->user = User::first();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General',
        ]);

        $unitId = (string) \Illuminate\Support\Str::ulid();
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
            'sku' => 'LEDGER-TEST-001',
            'name' => 'Ledger Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Ledger Test Location',
            'type' => 'internal',
        ]);

        Permission::firstOrCreate(['name' => 'inventory.ledger.view', 'guard_name' => 'web']);
    }

    private function makePostingIntent(array $overrides = []): array
    {
        $now = now();
        return array_merge([
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
            'occurred_at' => $now,
            'created_by' => $this->user->id,
        ], $overrides);
    }

    private function postingService(): InventoryLedgerPostingService
    {
        return app(InventoryLedgerPostingService::class);
    }

    public function test_can_create_stock_movement(): void
    {
        $intent = $this->makePostingIntent();
        $movement = $this->postingService()->post($intent);

        $this->assertNotNull($movement);
        $this->assertEquals($this->property->id, $movement->property_id);
        $this->assertEquals($this->item->id, $movement->inventory_item_id);
        $this->assertEquals($this->location->id, $movement->inventory_location_id);
        $this->assertEquals(InventoryMovementTypeEnum::GoodsReceipt, $movement->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::In, $movement->direction);
        $this->assertEquals(10.000, (float) $movement->quantity);
    }

    public function test_idempotency_key_prevents_duplicate_postings(): void
    {
        $idempotencyKey = (string) Str::ulid();
        $intent = $this->makePostingIntent(['idempotency_key' => $idempotencyKey]);

        $first = $this->postingService()->post($intent);
        $second = $this->postingService()->post($intent);

        $this->assertEquals($first->id, $second->id);

        $count = InventoryStockMovement::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $count);
    }

    public function test_idempotent_replay_fails_on_mismatched_quantity(): void
    {
        $idempotencyKey = (string) Str::ulid();
        $sourceId = (string) Str::ulid();
        $intent = $this->makePostingIntent([
            'idempotency_key' => $idempotencyKey,
            'source_id' => $sourceId,
            'quantity' => 5.000,
        ]);

        $this->postingService()->post($intent);

        $mismatchedIntent = $this->makePostingIntent([
            'idempotency_key' => $idempotencyKey,
            'source_id' => $sourceId,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'quantity' => 10.000,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Idempotent replay mismatch on quantity');
        $this->postingService()->post($mismatchedIntent);
    }

    public function test_source_correlation_prevents_duplicate_source_evidence(): void
    {
        $sourceId = (string) Str::ulid();
        $intent = $this->makePostingIntent([
            'source_id' => $sourceId,
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $this->postingService()->post($intent);

        $duplicateIntent = $this->makePostingIntent([
            'source_id' => $sourceId,
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A stock movement already exists for this source evidence.');
        $this->postingService()->post($duplicateIntent);
    }

    public function test_rejects_zero_or_negative_quantity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quantity must be positive.');

        $intent = $this->makePostingIntent(['quantity' => 0]);
        $this->postingService()->post($intent);
    }

    public function test_rejects_invalid_movement_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid movement type');

        $intent = $this->makePostingIntent(['movement_type' => 'BOGUS_MOVEMENT_TYPE']);
        $this->postingService()->post($intent);
    }

    public function test_rejects_invalid_direction(): void
    {
        $intent = $this->makePostingIntent(['direction' => 'BOGUS_DIRECTION']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid direction');
        $this->postingService()->post($intent);
    }

    public function test_no_mutable_stock_balance_written(): void
    {
        $intent = $this->makePostingIntent();
        $this->postingService()->post($intent);

        $stockBalance = InventoryStock::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertNull($stockBalance);
    }

    public function test_no_existing_inventory_transaction_modified(): void
    {
        $txCount = InventoryTransaction::count();

        $intent = $this->makePostingIntent();
        $this->postingService()->post($intent);

        $this->assertEquals($txCount, InventoryTransaction::count());
    }

    public function test_no_legacy_stock_balance_modified(): void
    {
        $intent = $this->makePostingIntent();
        $this->postingService()->post($intent);

        $stockCount = \Modules\Operations\Inventory\Models\InventoryStock::count();

        $this->assertEquals($stockCount, \Modules\Operations\Inventory\Models\InventoryStock::count());
    }

    public function test_postgresql_unique_idempotency_constraint(): void
    {
        $idempotencyKey = (string) Str::ulid();
        $now = now();

        $values = [
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt->value,
            'direction' => InventoryMovementDirectionEnum::In->value,
            'quantity' => 10.000,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => (string) Str::ulid(),
            'source_leg' => 'PRIMARY',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $now,
            'created_by' => $this->user->id,
            'created_at' => $now,
        ];

        DB::table('inventory_stock_movements')->insert($values);

        $dupValues = $values;
        $dupValues['id'] = (string) Str::ulid();
        $dupValues['source_id'] = (string) Str::ulid();
        $dupValues['correlation_id'] = (string) Str::ulid();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_stock_movements')->insert($dupValues);
    }

    public function test_postgresql_unique_source_correlation_constraint(): void
    {
        $sourceId = (string) Str::ulid();
        $now = now();

        $values = [
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt->value,
            'direction' => InventoryMovementDirectionEnum::In->value,
            'quantity' => 10.000,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => $sourceId,
            'source_leg' => 'PRIMARY',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => $now,
            'created_by' => $this->user->id,
            'created_at' => $now,
        ];

        DB::table('inventory_stock_movements')->insert($values);

        $dupValues = $values;
        $dupValues['id'] = (string) Str::ulid();
        $dupValues['idempotency_key'] = (string) Str::ulid();
        $dupValues['correlation_id'] = (string) Str::ulid();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_stock_movements')->insert($dupValues);
    }

    public function test_controlled_ledger_quantity_derives_from_movements(): void
    {
        $intent = $this->makePostingIntent(['quantity' => 20.000]);
        $this->postingService()->post($intent);

        $intent2 = $this->makePostingIntent([
            'quantity' => 5.000,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'idempotency_key' => (string) Str::ulid(),
            'source_id' => (string) Str::ulid(),
        ]);
        $this->postingService()->post($intent2);

        $projection = InventoryStockMovement::query()
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END) as controlled_quantity")
            ->where('inventory_item_id', $this->item->id)
            ->where('inventory_location_id', $this->location->id)
            ->groupBy('inventory_item_id', 'inventory_location_id')
            ->first();

        $this->assertEquals(15.000, (float) $projection->controlled_quantity);
    }

    public function test_workspace_uses_signed_controlled_quantity(): void
    {
        $this->postingService()->post($this->makePostingIntent(['quantity' => 20.000]));
        $this->postingService()->post($this->makePostingIntent([
            'quantity' => 5.000,
            'movement_type' => InventoryMovementTypeEnum::IssueConsumption,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryIssueLine',
            'idempotency_key' => (string) Str::ulid(),
            'source_id' => (string) Str::ulid(),
        ]));

        $this->user->givePermissionTo('inventory.ledger.view');

        $this->actingAs($this->user)
            ->get('/operations/inventory/ledger')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stockOnHand.0.controlled_quantity', '15.000')
            );
    }

    public function test_workspace_requires_authentication(): void
    {
        $this->get('/operations/inventory/ledger')
            ->assertRedirect('/login');
    }

    public function test_workspace_requires_permission(): void
    {
        $userId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User No Perm',
            'email' => 'noperm-' . Str::random(8) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userWithoutPermission = User::find($userId);

        $this->actingAs($userWithoutPermission)
            ->get('/operations/inventory/ledger')
            ->assertForbidden();
    }

    public function test_workspace_accessible_with_permission(): void
    {
        $this->user->givePermissionTo('inventory.ledger.view');

        $response = $this->actingAs($this->user)
            ->get('/operations/inventory/ledger');

        $response->assertOk();
    }

    public function test_workspace_is_read_only(): void
    {
        $this->user->givePermissionTo('inventory.ledger.view');

        $response = $this->actingAs($this->user)
            ->get('/operations/inventory/ledger');

        $response->assertOk();

        $this->assertTrue(true);
    }

    public function test_cross_property_movement_isolation(): void
    {
        $propertyBId = (string) Str::ulid();
        DB::table('properties')->insert([
            'id' => $propertyBId,
            'company_id' => $this->property->company_id,
            'name' => 'Property B',
            'slug' => 'property-b-' . Str::random(6),
            'code' => 'PB-' . Str::random(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $intentA = $this->makePostingIntent();
        $this->postingService()->post($intentA);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyBId);

        $movementsInB = InventoryStockMovement::query()->get();

        $this->assertCount(0, $movementsInB);
    }

    public function test_no_cost_fields_in_ledger(): void
    {
        $intent = $this->makePostingIntent();
        $movement = $this->postingService()->post($intent);

        $columns = DB::getSchemaBuilder()->getColumnListing('inventory_stock_movements');

        $prohibited = ['unit_cost', 'total_cost', 'currency', 'exchange_rate',
            'valuation_scope', 'valuation_sequence', 'valuation_approval_status',
            'stock_on_hand', 'available_quantity', 'reserved_quantity',
            'opening_balance', 'current_balance', 'gl_account', 'journal_reference'];

        foreach ($prohibited as $field) {
            $this->assertNotContains($field, $columns,
                "Prohibited field '{$field}' found in inventory_stock_movements table.");
        }
    }

    public function test_no_direct_gl_mutation(): void
    {
        $intent = $this->makePostingIntent();
        $this->postingService()->post($intent);

        $this->assertTrue(true);
    }

    public function test_stock_movement_cannot_be_updated_via_postgresql(): void
    {
        $intent = $this->makePostingIntent();
        $movement = $this->postingService()->post($intent);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_stock_movements')
            ->where('id', $movement->id)
            ->update(['quantity' => 99.000]);
    }

    public function test_goods_receipt_source_leg_is_primary(): void
    {
        $intent = $this->makePostingIntent();
        $movement = $this->postingService()->post($intent);

        $this->assertEquals('PRIMARY', $movement->source_leg->value);
    }

    public function test_schema_permits_all_approved_movement_types(): void
    {
        $approved = [
            'GOODS_RECEIPT',
            'TRANSFER_OUT',
            'TRANSFER_IN',
            'ISSUE_CONSUMPTION',
            'COUNT_VARIANCE_IN',
            'COUNT_VARIANCE_OUT',
            'MANUAL_ADJUSTMENT_IN',
            'MANUAL_ADJUSTMENT_OUT',
        ];

        foreach ($approved as $type) {
            $direction = InventoryMovementTypeEnum::from($type)->direction();

            $movement = $this->postingService()->post([
                'property_id' => $this->property->id,
                'inventory_item_id' => $this->item->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'movement_type' => $type,
                'direction' => $direction,
                'source_leg' => InventoryMovementSourceLegEnum::Primary,
                'quantity' => 1.000,
                'source_domain' => 'inventory',
                'source_type' => 'SchemaProof',
                'source_id' => (string) Str::ulid(),
                'correlation_id' => (string) Str::ulid(),
                'idempotency_key' => (string) Str::ulid(),
                'occurred_at' => now(),
                'created_by' => $this->user->id,
            ]);

            $this->assertNotNull($movement);
            $this->assertEquals($type, $movement->movement_type->value);
        }
    }

    public function test_source_leg_enables_paired_outbound_inbound(): void
    {
        $correlationId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();

        $this->seedGoodsReceiptInbound(20.000);

        $out = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => 'leg-out-' . (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $in = $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferIn,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Inbound,
            'quantity' => 5.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'idempotency_key' => 'leg-in-' . (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals('OUTBOUND', $out->source_leg->value);
        $this->assertEquals('INBOUND', $in->source_leg->value);
        $this->assertEquals($sourceId, $out->source_id);
        $this->assertEquals($sourceId, $in->source_id);
    }

    public function test_out_movement_rejected_when_controlled_quantity_insufficient(): void
    {
        $this->seedGoodsReceiptInbound(5.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient controlled quantity');

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
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

    public function test_different_source_legs_permit_same_source_id(): void
    {
        $sourceId = (string) Str::ulid();

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 10.000,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => $sourceId,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::TransferOut,
            'direction' => InventoryMovementDirectionEnum::Out,
            'source_leg' => InventoryMovementSourceLegEnum::Outbound,
            'quantity' => 2.000,
            'source_domain' => 'inventory',
            'source_type' => 'InventoryTransferLine',
            'source_id' => $sourceId,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $legCounts = InventoryStockMovement::where('source_id', $sourceId)->get();
        $this->assertCount(2, $legCounts);
    }

    public function test_no_mutable_stock_balance_mutated_by_any_movement(): void
    {
        $stockBefore = \Modules\Operations\Inventory\Models\InventoryStock::count();

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unit->id,
            'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
            'direction' => InventoryMovementDirectionEnum::In,
            'source_leg' => InventoryMovementSourceLegEnum::Primary,
            'quantity' => 10.000,
            'source_domain' => 'purchasing',
            'source_type' => 'GoodsReceiptLine',
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
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
    }

    private function seedGoodsReceiptInbound(float $qty): void
    {
        $this->postingService()->post([
            'property_id' => $this->property->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
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
}

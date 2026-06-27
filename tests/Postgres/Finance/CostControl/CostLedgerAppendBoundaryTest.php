<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Services\CostLedgerAppendService;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;

class CostLedgerAppendBoundaryTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private CostLedgerAppendService $service;
    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CostLedgerAppendService::class);

        $this->property = Property::first();
        $this->user = User::first();
        $this->actingAs($this->user);

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General'
        ]);

        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'ITM-COST-001',
            'name' => 'Cost Ledger Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Warehouse',
            'type' => 'internal',
        ]);
    }

    private function createTransaction(string $propertyId): string
    {
        $id = (string) Str::ulid();
        DB::table('inventory_transactions')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'transaction_type' => 'purchase_receipt',
            'quantity_change' => 10.0000,
            'unit_cost' => 5.5000,
            'quantity_before' => 0.0000,
            'quantity_after' => 10.0000,
            'total_cost' => 55.0000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_eligible_inventory_transaction_append_success(): void
    {
        $txId = $this->createTransaction($this->property->id);
        $idemKey = 'idem-key-' . Str::uuid();

        $intent = new CostLedgerEntryIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: $idemKey,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('10.0000'),
            unitCost: new AvcoDecimal('5.5000'),
            valueDelta: new AvcoDecimal('55.0000'),
            businessDate: Carbon::today()->toDateString(),
            occurredAt: now()->toIso8601String()
        );

        $entry = $this->service->append($intent);

        $this->assertNotNull($entry->id);
        $this->assertEquals($this->property->id, $entry->property_id);
        $this->assertEquals($txId, $entry->source_inventory_transaction_id);
        $this->assertEquals('receipt', $entry->entry_type);
        $this->assertEquals($idemKey, $entry->idempotency_key);
        $this->assertEquals(1, $entry->entry_sequence);
        $this->assertEquals('USD', $entry->currency_code);
        $this->assertEquals('10.0000', (string) $entry->quantity_delta);
        $this->assertEquals('5.5000', (string) $entry->unit_cost);
        $this->assertEquals('55.0000', (string) $entry->value_delta);

        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $entry->id,
            'property_id' => $this->property->id,
            'source_inventory_transaction_id' => $txId,
            'idempotency_key' => $idemKey,
        ]);
    }

    public function test_replay_idempotency_behavior(): void
    {
        $txId = $this->createTransaction($this->property->id);
        $idemKey = 'idem-key-dup';

        $intent = new CostLedgerEntryIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: $idemKey,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('10.0000'),
            unitCost: new AvcoDecimal('5.5000'),
            valueDelta: new AvcoDecimal('55.0000'),
            businessDate: Carbon::today()->toDateString(),
            occurredAt: now()->toIso8601String()
        );

        $this->service->append($intent);
        $this->assertDatabaseCount('cost_ledger_entries', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate idempotency detected. Controlled failure.');

        try {
            $this->service->append($intent);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 1);
        }
    }

    public function test_cross_property_source_provenance_rejection(): void
    {
        // Create second property
        $secondProperty = Property::create([
            'id' => (string) Str::ulid(),
            'company_id' => $this->property->company_id,
            'name' => 'Second Property',
            'slug' => 'second-property',
            'code' => 'PROP-2',
            'is_active' => true,
        ]);

        $txId = $this->createTransaction($secondProperty->id);
        $idemKey = 'idem-key-cross';

        $intent = new CostLedgerEntryIntent(
            propertyId: $this->property->id, // intent property mismatch
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: $idemKey,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('10.0000'),
            unitCost: new AvcoDecimal('5.5000'),
            valueDelta: new AvcoDecimal('55.0000'),
            businessDate: Carbon::today()->toDateString(),
            occurredAt: now()->toIso8601String()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Property scope mismatch between intent and source transaction.');

        try {
            $this->service->append($intent);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
        }
    }

    public function test_missing_source_provenance_rejection(): void
    {
        $idemKey = 'idem-key-missing';
        $fakeTxId = (string) Str::ulid();

        $intent = new CostLedgerEntryIntent(
            propertyId: $this->property->id,
            sourceInventoryTransactionId: $fakeTxId,
            priorCostLedgerEntryId: null,
            entryType: 'receipt',
            idempotencyKey: $idemKey,
            entrySequence: 1,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('10.0000'),
            unitCost: new AvcoDecimal('5.5000'),
            valueDelta: new AvcoDecimal('55.0000'),
            businessDate: Carbon::today()->toDateString(),
            occurredAt: now()->toIso8601String()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source InventoryTransaction not found.');

        try {
            $this->service->append($intent);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 0);
        }
    }
}

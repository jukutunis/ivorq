<?php

namespace Tests\Postgres\Finance\CostControl;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Services\ControlledValuationCostLedgerAdapter;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use RuntimeException;
use Tests\PostgresTestCase;

class ControlledValuationCostLedgerAdapterTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private ControlledValuationCostLedgerAdapter $adapter;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;
    private string $businessDate;
    private string $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = app(ControlledValuationCostLedgerAdapter::class);

        $this->property = Property::first();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'General',
        ]);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'CTRL-VAL-ADPTR-001',
            'name'                  => 'Controlled Valuation Adapter Test Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Adapter Test Warehouse',
            'type'        => 'internal',
        ]);

        $this->businessDate = Carbon::today()->toDateString();
        $this->occurredAt   = now()->toDateTimeString();
    }

    // -------------------------------------------------------------------------
    // Proof 1 + 2: valid controlled intent produces one CostLedgerEntry with
    // exact source identity, sequence, delta, unit cost, and idempotency fields
    // as expected by the existing legacy Cost Ledger contract
    // -------------------------------------------------------------------------
    public function test_valid_controlled_intent_produces_cost_ledger_entry_with_exact_legacy_fields(): void
    {
        $txId    = $this->createTransaction($this->property->id);
        $idemKey = 'ctrl-idem-' . (string) Str::ulid();

        $entry = $this->adapter->append(new ControlledValuationCostLedgerIntent(
            propertyId:                   $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               $idemKey,
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        ));

        $this->assertInstanceOf(CostLedgerEntry::class, $entry);
        $this->assertNotNull($entry->id);

        // Proof 2: persisted fields match exactly the legacy Cost Ledger contract
        $this->assertEquals($this->property->id, $entry->property_id);
        $this->assertEquals($txId,               $entry->source_inventory_transaction_id);
        $this->assertNull($entry->prior_cost_ledger_entry_id);
        $this->assertEquals('receipt',           $entry->entry_type);
        $this->assertEquals($idemKey,            $entry->idempotency_key);
        $this->assertEquals(1,                   $entry->entry_sequence);
        $this->assertEquals('USD',               $entry->currency_code);
        $this->assertEquals('10.0000',           (string) $entry->quantity_delta);
        $this->assertEquals('5.5000',            (string) $entry->unit_cost);
        $this->assertEquals('55.0000',           (string) $entry->value_delta);

        $this->assertDatabaseHas('cost_ledger_entries', [
            'id'                              => $entry->id,
            'property_id'                     => $this->property->id,
            'source_inventory_transaction_id' => $txId,
            'idempotency_key'                 => $idemKey,
            'entry_sequence'                  => 1,
        ]);
    }

    // -------------------------------------------------------------------------
    // Proof 3: repeating the same controlled intent preserves the current
    // existing idempotency behavior — no second ledger entry is created
    // -------------------------------------------------------------------------
    public function test_repeating_same_controlled_intent_raises_controlled_duplicate_failure(): void
    {
        $txId    = $this->createTransaction($this->property->id);
        $idemKey = 'ctrl-idem-dup-' . (string) Str::ulid();

        $intent = new ControlledValuationCostLedgerIntent(
            propertyId:                   $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               $idemKey,
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        );

        $this->adapter->append($intent);
        $this->assertDatabaseCount('cost_ledger_entries', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate idempotency detected. Controlled failure.');

        try {
            $this->adapter->append($intent);
        } finally {
            $this->assertDatabaseCount('cost_ledger_entries', 1);
        }
    }

    // -------------------------------------------------------------------------
    // Proof 4a: blank propertyId is rejected at construction before any append
    // -------------------------------------------------------------------------
    public function test_blank_property_id_is_rejected_before_append(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/propertyId/');

        new ControlledValuationCostLedgerIntent(
            propertyId:                   '',
            sourceInventoryTransactionId: (string) Str::ulid(),
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               'some-key',
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        );
    }

    // -------------------------------------------------------------------------
    // Proof 4b: blank idempotency key is rejected at construction
    // -------------------------------------------------------------------------
    public function test_blank_idempotency_key_is_rejected_before_append(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/idempotencyKey/');

        new ControlledValuationCostLedgerIntent(
            propertyId:                   (string) Str::ulid(),
            sourceInventoryTransactionId: (string) Str::ulid(),
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               '',
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        );
    }

    // -------------------------------------------------------------------------
    // Proof 4c: blank sourceInventoryTransactionId (invalid source evidence)
    // is rejected at construction
    // -------------------------------------------------------------------------
    public function test_blank_source_inventory_transaction_id_is_rejected_before_append(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sourceInventoryTransactionId/');

        new ControlledValuationCostLedgerIntent(
            propertyId:                   (string) Str::ulid(),
            sourceInventoryTransactionId: '',
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               'some-key',
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        );
    }

    // -------------------------------------------------------------------------
    // Proof 4d: non-positive entrySequence is rejected at construction
    // -------------------------------------------------------------------------
    public function test_non_positive_entry_sequence_is_rejected_before_append(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/entrySequence must be positive/');

        new ControlledValuationCostLedgerIntent(
            propertyId:                   (string) Str::ulid(),
            sourceInventoryTransactionId: (string) Str::ulid(),
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               'some-key',
            entrySequence:                0,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        );
    }

    // -------------------------------------------------------------------------
    // Proof 4e: invalid entryType (not in allowed-type list) is rejected
    // -------------------------------------------------------------------------
    public function test_invalid_entry_type_is_rejected_before_append(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/entryType/');

        new ControlledValuationCostLedgerIntent(
            propertyId:                   (string) Str::ulid(),
            sourceInventoryTransactionId: (string) Str::ulid(),
            priorCostLedgerEntryId:       null,
            entryType:                    'synthetic_unknown',
            idempotencyKey:               'some-key',
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        );
    }

    // -------------------------------------------------------------------------
    // Proof 5: adapter append does not modify any side-effect table
    // -------------------------------------------------------------------------
    public function test_adapter_append_does_not_alter_side_effect_tables(): void
    {
        $txId = $this->createTransaction($this->property->id);

        // Capture baseline after test setup and transaction creation
        // but before the adapter call.
        $avcoCount      = DB::table('cost_avco_states')->count();
        $enrollCount    = DB::table('cost_authority_enrollment_groups')->count();
        $txCount        = DB::table('inventory_transactions')->count();
        $outboxCount    = DB::table('outbox_messages')->count();
        $candidateCount = DB::table('journal_candidates')->count();
        $glCount        = DB::table('gl_journal_entries')->count();
        $balanceCount   = DB::table('gl_ledger_balances')->count();

        $this->adapter->append(new ControlledValuationCostLedgerIntent(
            propertyId:                   $this->property->id,
            sourceInventoryTransactionId: $txId,
            priorCostLedgerEntryId:       null,
            entryType:                    'receipt',
            idempotencyKey:               'side-effect-key-' . (string) Str::ulid(),
            entrySequence:                1,
            currencyCode:                 'USD',
            quantityDelta:                new AvcoDecimal('10.0000'),
            unitCost:                     new AvcoDecimal('5.5000'),
            valueDelta:                   new AvcoDecimal('55.0000'),
            businessDate:                 $this->businessDate,
            occurredAt:                   $this->occurredAt,
        ));

        $this->assertEquals($avcoCount,      DB::table('cost_avco_states')->count());
        $this->assertEquals($enrollCount,    DB::table('cost_authority_enrollment_groups')->count());
        $this->assertEquals($txCount,        DB::table('inventory_transactions')->count());
        $this->assertEquals($outboxCount,    DB::table('outbox_messages')->count());
        $this->assertEquals($candidateCount, DB::table('journal_candidates')->count());
        $this->assertEquals($glCount,        DB::table('gl_journal_entries')->count());
        $this->assertEquals($balanceCount,   DB::table('gl_ledger_balances')->count());

        // Exactly one Cost Ledger entry was appended.
        $this->assertEquals(
            1,
            DB::table('cost_ledger_entries')
                ->where('property_id', $this->property->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 6: no production service file references this adapter in this slice
    // -------------------------------------------------------------------------
    public function test_no_existing_production_service_imports_the_controlled_valuation_adapter(): void
    {
        $modulePath = base_path('Modules');
        $callers    = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, 'ControlledValuationCostLedgerAdapter.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), 'ControlledValuationCostLedgerAdapter')) {
                $callers[] = $path;
            }
        }

        $this->assertEmpty(
            $callers,
            'No production service should reference ControlledValuationCostLedgerAdapter in this slice. ' .
            'Found references in: ' . implode(', ', $callers)
        );
    }

    // -------------------------------------------------------------------------
    // Internal helper
    // -------------------------------------------------------------------------

    private function createTransaction(string $propertyId): string
    {
        $id = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id'               => $id,
            'property_id'      => $propertyId,
            'item_id'          => $this->item->id,
            'location_id'      => $this->location->id,
            'transaction_type' => 'purchase_receipt',
            'quantity_change'  => '10.0000',
            'unit_cost'        => '5.50',
            'quantity_before'  => '0.0000',
            'quantity_after'   => '10.0000',
            'total_cost'       => '55.00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $id;
    }
}

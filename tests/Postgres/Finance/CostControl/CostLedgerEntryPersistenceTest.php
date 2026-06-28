<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Tests\PostgresTestCase;

class CostLedgerEntryPersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private CostLedgerRepository $repo;

    private string $propertyId;
    private string $locationId;
    private string $itemId;
    private string $valuationScope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = app(CostLedgerRepository::class);

        $this->propertyId     = (string) Str::ulid();
        $this->locationId     = (string) Str::ulid();
        $this->itemId         = (string) Str::ulid();
        $this->valuationScope = "property:{$this->propertyId}:location:{$this->locationId}:item:{$this->itemId}";
    }

    // -------------------------------------------------------------------------
    // Proof 1: valid append persists all identity, sequence, and AVCO fields
    // -------------------------------------------------------------------------
    public function test_valid_append_persists_all_required_fields(): void
    {
        $txId = (string) Str::ulid();

        $entry = $this->repo->append($this->makeAttributes([
            'inventory_transaction_id'         => $txId,
            'valuation_sequence'               => 7,
            'business_date'                    => '2026-07-15',
            'quantity_before'                  => '100.0000',
            'quantity_after'                   => '105.0000',
            'carrying_value_before'            => '1000.0000',
            'carrying_value_after'             => '1060.0000',
            'weighted_average_unit_cost_after' => '10.0952',
        ]));

        $this->assertInstanceOf(CostLedgerEntry::class, $entry);

        $row = DB::table('cost_ledger_entries')->where('id', $entry->id)->first();

        $this->assertNotNull($row);
        $this->assertEquals($this->propertyId,   $row->property_id);
        $this->assertEquals($this->locationId,   $row->location_id);
        $this->assertEquals($this->itemId,       $row->item_id);
        $this->assertEquals($this->valuationScope, $row->valuation_scope);
        $this->assertEquals(7,                   (int) $row->valuation_sequence);
        $this->assertEquals($txId,               $row->inventory_transaction_id);
        $this->assertEquals('2026-07-15',        $row->business_date);
        $this->assertEquals('100.0000',          $row->quantity_before);
        $this->assertEquals('105.0000',          $row->quantity_after);
        $this->assertEquals('1000.0000',         $row->carrying_value_before);
        $this->assertEquals('1060.0000',         $row->carrying_value_after);
        $this->assertEquals('10.0952',           $row->weighted_average_unit_cost_after);
        $this->assertNotNull($row->created_at);
    }

    // -------------------------------------------------------------------------
    // Proof 2: findByInventoryTransactionId returns the appended entry
    // -------------------------------------------------------------------------
    public function test_find_by_inventory_transaction_id_returns_appended_entry(): void
    {
        $txId  = (string) Str::ulid();
        $entry = $this->repo->append($this->makeAttributes(['inventory_transaction_id' => $txId]));

        $found = $this->repo->findByInventoryTransactionId($txId);

        $this->assertInstanceOf(CostLedgerEntry::class, $found);
        $this->assertEquals($entry->id, $found->id);
        $this->assertEquals($txId, $found->inventory_transaction_id);
    }

    // -------------------------------------------------------------------------
    // Proof 2b: findByInventoryTransactionId returns null for unknown transaction
    // -------------------------------------------------------------------------
    public function test_find_by_inventory_transaction_id_returns_null_when_not_found(): void
    {
        $found = $this->repo->findByInventoryTransactionId((string) Str::ulid());

        $this->assertNull($found);
    }

    // -------------------------------------------------------------------------
    // Proof 3: duplicate property + valuation_scope + valuation_sequence is rejected
    // -------------------------------------------------------------------------
    public function test_duplicate_property_scope_sequence_is_rejected(): void
    {
        $this->repo->append($this->makeAttributes([
            'valuation_sequence'       => 1,
            'inventory_transaction_id' => (string) Str::ulid(),
        ]));

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->repo->append($this->makeAttributes([
            'valuation_sequence'       => 1,                   // same scope + sequence
            'inventory_transaction_id' => (string) Str::ulid(), // different tx
        ]));
    }

    // -------------------------------------------------------------------------
    // Proof 4: duplicate inventory_transaction_id is rejected
    // -------------------------------------------------------------------------
    public function test_duplicate_inventory_transaction_id_is_rejected(): void
    {
        $txId = (string) Str::ulid();

        $this->repo->append($this->makeAttributes([
            'valuation_sequence'       => 1,
            'inventory_transaction_id' => $txId,
        ]));

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->repo->append($this->makeAttributes([
            'valuation_sequence'       => 2,   // different sequence
            'inventory_transaction_id' => $txId, // same tx
        ]));
    }

    // -------------------------------------------------------------------------
    // Proof 5: valuation_sequence <= 0 is rejected at the database boundary
    // -------------------------------------------------------------------------
    public function test_non_positive_valuation_sequence_zero_is_rejected_at_database_boundary(): void
    {
        // Bypass repository PHP validation to hit the CHECK constraint directly.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('cost_ledger_entries')->insert(array_merge(
            ['id' => (string) Str::ulid()],
            $this->makeAttributes(['valuation_sequence' => 0])
        ));
    }

    public function test_negative_valuation_sequence_is_rejected_at_database_boundary(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('cost_ledger_entries')->insert(array_merge(
            ['id' => (string) Str::ulid()],
            $this->makeAttributes(['valuation_sequence' => -1])
        ));
    }

    // -------------------------------------------------------------------------
    // Proof 6: UPDATE of an existing Cost Ledger entry is rejected by trigger
    // -------------------------------------------------------------------------
    public function test_update_of_existing_entry_is_rejected_by_trigger(): void
    {
        $entry = $this->repo->append($this->makeAttributes());

        try {
            DB::table('cost_ledger_entries')
                ->where('id', $entry->id)
                ->update(['quantity_after' => '999.0000']);
            $this->fail('Expected trigger exception for UPDATE; none thrown.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Row is unchanged
        $row = DB::table('cost_ledger_entries')->where('id', $entry->id)->first();
        $this->assertNotEquals('999.0000', $row->quantity_after);
    }

    // -------------------------------------------------------------------------
    // Proof 7: DELETE of an existing Cost Ledger entry is rejected by trigger
    // -------------------------------------------------------------------------
    public function test_delete_of_existing_entry_is_rejected_by_trigger(): void
    {
        $entry = $this->repo->append($this->makeAttributes());

        try {
            DB::table('cost_ledger_entries')
                ->where('id', $entry->id)
                ->delete();
            $this->fail('Expected trigger exception for DELETE; none thrown.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Row still exists
        $this->assertEquals(
            1,
            DB::table('cost_ledger_entries')->where('id', $entry->id)->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 8: appending does not alter any side-effect table
    // -------------------------------------------------------------------------
    public function test_appending_does_not_alter_side_effect_tables(): void
    {
        $avcoStatesBefore      = DB::table('cost_avco_states')->count();
        $enrollmentsBefore     = DB::table('cost_authority_enrollment_groups')->count();
        $inventoryTxsBefore    = DB::table('inventory_transactions')->count();
        $outboxBefore          = DB::table('outbox_messages')->count();
        $candidatesBefore      = DB::table('journal_candidates')->count();
        $glEntriesBefore       = DB::table('gl_journal_entries')->count();
        $ledgerBalancesBefore  = DB::table('gl_ledger_balances')->count();

        $this->repo->append($this->makeAttributes());

        $this->assertEquals($avcoStatesBefore,     DB::table('cost_avco_states')->count());
        $this->assertEquals($enrollmentsBefore,    DB::table('cost_authority_enrollment_groups')->count());
        $this->assertEquals($inventoryTxsBefore,   DB::table('inventory_transactions')->count());
        $this->assertEquals($outboxBefore,         DB::table('outbox_messages')->count());
        $this->assertEquals($candidatesBefore,     DB::table('journal_candidates')->count());
        $this->assertEquals($glEntriesBefore,      DB::table('gl_journal_entries')->count());
        $this->assertEquals($ledgerBalancesBefore, DB::table('gl_ledger_balances')->count());

        // Only cost_ledger_entries gained one row
        $this->assertEquals(
            1,
            DB::table('cost_ledger_entries')
                ->where('property_id', $this->propertyId)
                ->where('valuation_scope', $this->valuationScope)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 8b: repository rejects missing required fields before any DB call
    // -------------------------------------------------------------------------
    public function test_append_rejects_missing_required_field_before_db(): void
    {
        $attrs = $this->makeAttributes();
        unset($attrs['valuation_sequence']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/valuation_sequence/');

        $this->repo->append($attrs);

        $this->assertEquals(0, DB::table('cost_ledger_entries')->count());
    }

    // -------------------------------------------------------------------------
    // Proof 8c: repository rejects non-positive valuation_sequence in PHP layer
    // -------------------------------------------------------------------------
    public function test_append_rejects_non_positive_valuation_sequence_in_php_layer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/valuation_sequence must be positive/');

        $this->repo->append($this->makeAttributes(['valuation_sequence' => 0]));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function makeAttributes(array $overrides = []): array
    {
        return array_merge([
            'property_id'                      => $this->propertyId,
            'location_id'                      => $this->locationId,
            'item_id'                          => $this->itemId,
            'valuation_scope'                  => $this->valuationScope,
            'valuation_sequence'               => 1,
            'inventory_transaction_id'         => (string) Str::ulid(),
            'business_date'                    => '2026-07-01',
            'quantity_before'                  => '100.0000',
            'quantity_after'                   => '105.0000',
            'carrying_value_before'            => '1000.0000',
            'carrying_value_after'             => '1060.0000',
            'weighted_average_unit_cost_after' => '10.0952',
        ], $overrides);
    }
}

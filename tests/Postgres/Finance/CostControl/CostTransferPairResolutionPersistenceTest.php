<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Modules\Finance\CostControl\Models\CostTransferPairResolution;
use Modules\Finance\CostControl\Repositories\CostTransferPairResolutionRepository;

class CostTransferPairResolutionPersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // Disable DatabaseTransactions wrapping so we can test the "requires active
    // transaction" guards by calling repository methods outside any transaction.
    protected function connectionsToTransact(): array
    {
        return [];
    }

    private CostTransferPairResolutionRepository $repo;
    private string $propertyId;
    private string $sourceDocId;
    private string $sourceLineId;
    private string $sourceTxId;
    private string $destinationTxId;
    private string $sourceScope;
    private string $destinationScope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new CostTransferPairResolutionRepository();

        $this->propertyId        = (string) Str::ulid();
        $this->sourceDocId       = (string) Str::ulid();
        $this->sourceLineId      = (string) Str::ulid();
        $this->sourceTxId        = (string) Str::ulid();
        $this->destinationTxId   = (string) Str::ulid();
        $this->sourceScope       = "property:{$this->propertyId}:location:LOCA:item:ITEM1";
        $this->destinationScope  = "property:{$this->propertyId}:location:LOCB:item:ITEM1";
    }

    private function makeIdentity(array $overrides = []): array
    {
        return array_merge([
            'property_id'                          => $this->propertyId,
            'source_document_id'                   => $this->sourceDocId,
            'source_line_id'                       => $this->sourceLineId,
            'source_inventory_transaction_id'      => $this->sourceTxId,
            'destination_inventory_transaction_id' => $this->destinationTxId,
            'source_valuation_scope'               => $this->sourceScope,
            'destination_valuation_scope'          => $this->destinationScope,
            'source_valuation_sequence'            => 1,
            'destination_valuation_sequence'       => 1,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Transaction guards
    // -------------------------------------------------------------------------

    public function test_bootstrap_and_lock_requires_active_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an active outer transaction/');

        $this->repo->bootstrapAndLock($this->makeIdentity());
    }

    public function test_find_and_lock_requires_active_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an active outer transaction/');

        $this->repo->findAndLock($this->propertyId, $this->sourceDocId, $this->sourceLineId);
    }

    public function test_freeze_source_unit_cost_requires_active_transaction(): void
    {
        $resolution = DB::transaction(fn () => $this->repo->bootstrapAndLock($this->makeIdentity()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an active outer transaction/');

        $this->repo->freezeSourceUnitCost($resolution, '12.5000');
    }

    public function test_record_blocking_reason_requires_active_transaction(): void
    {
        $resolution = DB::transaction(fn () => $this->repo->bootstrapAndLock($this->makeIdentity()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an active outer transaction/');

        $this->repo->recordBlockingReason($resolution, 'SEQUENCE_GAP_DETECTED');
    }

    public function test_mark_applied_requires_active_transaction(): void
    {
        $resolution = DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            return $row;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an active outer transaction/');

        $this->repo->markApplied($resolution);
    }

    public function test_mark_delivered_requires_active_transaction(): void
    {
        $resolution = DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->markApplied($row);
            return $row;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an active outer transaction/');

        $this->repo->markDelivered($resolution);
    }

    // -------------------------------------------------------------------------
    // Idempotent bootstrap
    // -------------------------------------------------------------------------

    public function test_bootstrap_creates_pending_row_with_correct_identity(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());

            $this->assertNotNull($row->id);
            $this->assertEquals($this->propertyId, $row->property_id);
            $this->assertEquals($this->sourceDocId, $row->source_document_id);
            $this->assertEquals($this->sourceLineId, $row->source_line_id);
            $this->assertEquals($this->sourceTxId, $row->source_inventory_transaction_id);
            $this->assertEquals($this->destinationTxId, $row->destination_inventory_transaction_id);
            $this->assertEquals($this->sourceScope, $row->source_valuation_scope);
            $this->assertEquals($this->destinationScope, $row->destination_valuation_scope);
            $this->assertEquals(1, $row->source_valuation_sequence);
            $this->assertEquals(1, $row->destination_valuation_sequence);
            $this->assertNull($row->frozen_source_unit_cost);
            $this->assertEquals('pending', $row->lifecycle_status);
            $this->assertNull($row->blocking_reason_code);
        });

        $this->assertDatabaseCount('cost_transfer_pair_resolutions', 1);
    }

    public function test_bootstrap_is_idempotent_across_separate_transactions(): void
    {
        $firstId = null;
        DB::transaction(function () use (&$firstId) {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $firstId = $row->id;
        });

        $this->assertDatabaseCount('cost_transfer_pair_resolutions', 1);

        DB::transaction(function () use ($firstId) {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->assertEquals($firstId, $row->id, 'Second bootstrap must return the same row');
        });

        $this->assertDatabaseCount('cost_transfer_pair_resolutions', 1);
    }

    public function test_find_and_lock_returns_null_when_no_row_exists(): void
    {
        $result = DB::transaction(fn () =>
            $this->repo->findAndLock($this->propertyId, $this->sourceDocId, $this->sourceLineId)
        );

        $this->assertNull($result);
    }

    public function test_find_and_lock_returns_existing_row(): void
    {
        DB::transaction(fn () => $this->repo->bootstrapAndLock($this->makeIdentity()));

        $found = DB::transaction(fn () =>
            $this->repo->findAndLock($this->propertyId, $this->sourceDocId, $this->sourceLineId)
        );

        $this->assertNotNull($found);
        $this->assertEquals($this->sourceDocId, $found->source_document_id);
        $this->assertEquals('pending', $found->lifecycle_status);
    }

    // -------------------------------------------------------------------------
    // Unique pair-key constraint
    // -------------------------------------------------------------------------

    public function test_unique_pair_key_constraint_rejects_duplicate_insert(): void
    {
        DB::transaction(fn () => $this->repo->bootstrapAndLock($this->makeIdentity()));

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Direct insert bypassing insertOrIgnore to prove the DB-level constraint fires
        DB::table('cost_transfer_pair_resolutions')->insert([
            'id'                                   => (string) Str::ulid(),
            'property_id'                          => $this->propertyId,
            'source_document_id'                   => $this->sourceDocId,
            'source_line_id'                       => $this->sourceLineId,
            'source_inventory_transaction_id'      => $this->sourceTxId,
            'destination_inventory_transaction_id' => $this->destinationTxId,
            'source_valuation_scope'               => $this->sourceScope,
            'destination_valuation_scope'          => $this->destinationScope,
            'source_valuation_sequence'            => 1,
            'destination_valuation_sequence'       => 1,
            'lifecycle_status'                     => 'pending',
            'created_at'                           => now(),
            'updated_at'                           => now(),
        ]);
    }

    public function test_different_source_line_id_creates_independent_row(): void
    {
        $lineId2 = (string) Str::ulid();

        DB::transaction(fn () => $this->repo->bootstrapAndLock($this->makeIdentity()));
        DB::transaction(fn () => $this->repo->bootstrapAndLock($this->makeIdentity(['source_line_id' => $lineId2])));

        $this->assertDatabaseCount('cost_transfer_pair_resolutions', 2);
    }

    // -------------------------------------------------------------------------
    // Freeze: exact decimal and freeze-once
    // -------------------------------------------------------------------------

    public function test_freeze_sets_frozen_cost_and_advances_lifecycle_to_frozen(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '18.7500');

            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('18.7500', $fresh->frozen_source_unit_cost);
            $this->assertEquals('frozen', $fresh->lifecycle_status);
        });
    }

    public function test_freeze_stores_zero_as_valid_non_negative_cost(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '0.0000');

            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('0.0000', $fresh->frozen_source_unit_cost);
            $this->assertEquals('frozen', $fresh->lifecycle_status);
        });
    }

    public function test_freeze_rejects_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '-1.0000');
        });
    }

    public function test_freeze_is_rejected_when_already_frozen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/requires lifecycle_status='pending'/");

        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->freezeSourceUnitCost($row, '20.0000');
        });
    }

    // -------------------------------------------------------------------------
    // PostgreSQL trigger: frozen cost is immutable once set
    // -------------------------------------------------------------------------

    public function test_pg_trigger_rejects_overwriting_frozen_cost_via_direct_update(): void
    {
        $rowId = null;
        DB::transaction(function () use (&$rowId) {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $rowId = $row->id;
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/frozen_source_unit_cost is immutable after initial set/');

        DB::table('cost_transfer_pair_resolutions')
            ->where('id', $rowId)
            ->update(['frozen_source_unit_cost' => '99.9999']);
    }

    // -------------------------------------------------------------------------
    // PostgreSQL trigger: immutable identity fields
    // -------------------------------------------------------------------------

    public function test_pg_trigger_rejects_mutating_property_id(): void
    {
        $rowId = null;
        DB::transaction(function () use (&$rowId) {
            $rowId = $this->repo->bootstrapAndLock($this->makeIdentity())->id;
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/property_id is immutable/');

        DB::table('cost_transfer_pair_resolutions')
            ->where('id', $rowId)
            ->update(['property_id' => (string) Str::ulid()]);
    }

    public function test_pg_trigger_rejects_mutating_source_document_id(): void
    {
        $rowId = null;
        DB::transaction(function () use (&$rowId) {
            $rowId = $this->repo->bootstrapAndLock($this->makeIdentity())->id;
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/source_document_id is immutable/');

        DB::table('cost_transfer_pair_resolutions')
            ->where('id', $rowId)
            ->update(['source_document_id' => (string) Str::ulid()]);
    }

    public function test_pg_trigger_rejects_mutating_source_inventory_transaction_id(): void
    {
        $rowId = null;
        DB::transaction(function () use (&$rowId) {
            $rowId = $this->repo->bootstrapAndLock($this->makeIdentity())->id;
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/source_inventory_transaction_id is immutable/');

        DB::table('cost_transfer_pair_resolutions')
            ->where('id', $rowId)
            ->update(['source_inventory_transaction_id' => (string) Str::ulid()]);
    }

    public function test_pg_trigger_rejects_mutating_source_valuation_scope(): void
    {
        $rowId = null;
        DB::transaction(function () use (&$rowId) {
            $rowId = $this->repo->bootstrapAndLock($this->makeIdentity())->id;
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/source_valuation_scope is immutable/');

        DB::table('cost_transfer_pair_resolutions')
            ->where('id', $rowId)
            ->update(['source_valuation_scope' => 'tampered:scope']);
    }

    // -------------------------------------------------------------------------
    // Blocking reason
    // -------------------------------------------------------------------------

    public function test_blocking_reason_can_be_set_from_pending(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->recordBlockingReason($row, 'SEQUENCE_GAP_DETECTED');

            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('SEQUENCE_GAP_DETECTED', $fresh->blocking_reason_code);
            $this->assertEquals('pending', $fresh->lifecycle_status);
        });
    }

    public function test_blocking_reason_can_be_set_from_frozen(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->recordBlockingReason($row, 'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS');

            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS', $fresh->blocking_reason_code);
            $this->assertEquals('frozen', $fresh->lifecycle_status);
        });
    }

    public function test_blocking_reason_rejected_from_applied(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/allowed from 'pending' or 'frozen'/");

        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->markApplied($row);
            $this->repo->recordBlockingReason($row, 'LATE_REASON');
        });
    }

    // -------------------------------------------------------------------------
    // Lifecycle transition guards
    // -------------------------------------------------------------------------

    public function test_mark_applied_advances_frozen_to_applied(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->markApplied($row);

            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('applied', $fresh->lifecycle_status);
        });
    }

    public function test_mark_applied_rejected_from_pending(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/requires lifecycle_status='frozen'/");

        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->markApplied($row);
        });
    }

    public function test_mark_delivered_advances_applied_to_delivered(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->markApplied($row);
            $this->repo->markDelivered($row);

            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('delivered', $fresh->lifecycle_status);
        });
    }

    public function test_mark_delivered_rejected_from_frozen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/requires lifecycle_status='applied'/");

        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->freezeSourceUnitCost($row, '10.0000');
            $this->repo->markDelivered($row);
        });
    }

    public function test_mark_delivered_rejected_from_pending(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/requires lifecycle_status='applied'/");

        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());
            $this->repo->markDelivered($row);
        });
    }

    public function test_full_lifecycle_transition_pending_frozen_applied_delivered(): void
    {
        DB::transaction(function () {
            $row = $this->repo->bootstrapAndLock($this->makeIdentity());

            $this->assertEquals('pending', $row->lifecycle_status);

            $this->repo->freezeSourceUnitCost($row, '15.2500');
            $this->assertEquals('frozen', CostTransferPairResolution::find($row->id)->lifecycle_status);

            $this->repo->markApplied($row);
            $this->assertEquals('applied', CostTransferPairResolution::find($row->id)->lifecycle_status);

            $this->repo->markDelivered($row);
            $fresh = CostTransferPairResolution::find($row->id);
            $this->assertEquals('delivered', $fresh->lifecycle_status);
            $this->assertEquals('15.2500', $fresh->frozen_source_unit_cost);
        });
    }

    // -------------------------------------------------------------------------
    // bootstrap missing required fields
    // -------------------------------------------------------------------------

    public function test_bootstrap_rejects_missing_required_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires non-empty/');

        $identity = $this->makeIdentity();
        unset($identity['source_valuation_scope']);

        DB::transaction(fn () => $this->repo->bootstrapAndLock($identity));
    }
}

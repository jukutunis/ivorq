<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Operations\Housekeeping\Models\HousekeepingInspectionClaimReassignment;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingInspectionClaimRecoveryData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimRecoveryMigrationProofTest extends PostgresTestCase
{
    use CreatesHousekeepingInspectionClaimRecoveryData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInspectionClaimRecoveryFixture();
    }

    public function test_postgresql_objects_raw_guards_immutability_and_predecessor_preserving_reapply(): void
    {
        $this->assertTrue(Schema::hasColumns('housekeeping_inspection_claim_reassignments', [
            'id', 'property_id', 'room_inspection_id', 'original_claimant_id', 'replacement_claimant_id',
            'intervened_by', 'original_ineligibility_code', 'reason', 'idempotency_key', 'source_hash',
            'evidence_version', 'occurred_at', 'created_at',
        ]));
        foreach ([
            'hk_p19_inspection_claim_reassignment_source_hash',
            'hk_p19_inspection_claim_reassignment_insert_guard',
            'hk_p19_inspection_claim_reassignment_immutable',
        ] as $function) {
            $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM pg_proc WHERE proname = ?', [$function]));
        }
        foreach (['hk_p19_reassignment_property_inspection_unique', 'hk_p19_reassignment_property_key_unique'] as $index) {
            $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM pg_indexes WHERE tablename = ? AND indexname = ?', ['housekeeping_inspection_claim_reassignments', $index]));
        }

        [, , $inspection] = $this->p19ClaimedInspection('P19-M-GUARDS');
        $p17Fields = ['supervisor_id', 'claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'];
        $p17 = collect($p17Fields)->mapWithKeys(fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)])->all();
        $this->p19MakeOriginalInactive();
        $recovery = $this->p19Recover($inspection)->reassignment;

        foreach ([
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->where('id', $recovery->id)->update(['reason' => 'raw rewrite']),
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->where('id', $recovery->id)->delete(),
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->insert(['id' => (string) Str::ulid()]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Expected PostgreSQL recovery guard rejection.');
            } catch (QueryException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $this->assertSame(1, HousekeepingInspectionClaimReassignment::count());
        $this->assertSame($p17, collect($p17Fields)->mapWithKeys(fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)])->all());

        $migration = require base_path('Modules/Operations/Housekeeping/database/migrations/2026_08_13_000001_control_housekeeping_inspection_claim_reassignments.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('housekeeping_inspection_claim_reassignments'));
        $this->assertTrue(Schema::hasColumns('room_inspections', ['claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version']));
        $this->assertSame(1, (int) DB::scalar("SELECT COUNT(*) FROM pg_proc WHERE proname = 'hk_p17_inspection_claim_source_hash'"));
        $this->assertSame($p17, collect($p17Fields)->mapWithKeys(fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)])->all());

        $migration->up();
        $this->assertTrue(Schema::hasTable('housekeeping_inspection_claim_reassignments'));
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
        $this->assertSame($p17, collect($p17Fields)->mapWithKeys(fn (string $field) => [$field => $inspection->fresh()->getRawOriginal($field)])->all());
    }
}

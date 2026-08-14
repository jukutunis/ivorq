<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\HousekeepingInspectionClaimReassignment;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimRecoveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingInspectionClaimRecoveryData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimRecoveryMigrationProofTest extends PostgresTestCase
{
    use CreatesHousekeepingInspectionClaimRecoveryData;
    use RefreshDatabase;

    private const P17_FIELDS = [
        'supervisor_id',
        'claimed_at',
        'claim_idempotency_key',
        'claim_source_hash',
        'claim_evidence_version',
    ];

    private const RAW_COLUMNS = [
        'id',
        'property_id',
        'room_inspection_id',
        'original_claimant_id',
        'replacement_claimant_id',
        'intervened_by',
        'original_ineligibility_code',
        'reason',
        'idempotency_key',
        'source_hash',
        'evidence_version',
        'occurred_at',
        'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInspectionClaimRecoveryFixture();
    }

    public function test_postgresql_objects_service_timestamp_evidence_immutability_and_predecessor_preserving_reapply(): void
    {
        $this->assertTrue(Schema::hasColumns('housekeeping_inspection_claim_reassignments', self::RAW_COLUMNS));
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
        $p17 = $this->p17Snapshot($inspection);
        $this->p19MakeOriginalInactive();
        $recovery = $this->p19Recover($inspection)->reassignment;
        $timestamps = DB::table('housekeeping_inspection_claim_reassignments')
            ->where('id', $recovery->id)
            ->first(['occurred_at', 'created_at']);

        $this->assertNotNull($timestamps->occurred_at);
        $this->assertNotNull($timestamps->created_at);
        $this->assertSame($timestamps->occurred_at, $timestamps->created_at);
        $this->assertFalse($recovery->usesTimestamps());
        $this->assertFalse(Schema::hasColumn('housekeeping_inspection_claim_reassignments', 'updated_at'));

        $this->assertRawWriteRejected(
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->where('id', $recovery->id)->update(['reason' => 'raw rewrite']),
            'P0001',
            'HK_P19_INSPECTION_CLAIM_RECOVERY_IMMUTABLE',
        );
        $this->assertP17Unchanged($inspection, $p17);
        $this->assertRawWriteRejected(
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->where('id', $recovery->id)->delete(),
            'P0001',
            'HK_P19_INSPECTION_CLAIM_RECOVERY_DELETE_PROHIBITED',
        );
        $this->assertP17Unchanged($inspection, $p17);
        $this->assertSame(1, HousekeepingInspectionClaimReassignment::count());

        $migration = require base_path('Modules/Operations/Housekeeping/database/migrations/2026_08_13_000001_control_housekeeping_inspection_claim_reassignments.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('housekeeping_inspection_claim_reassignments'));
        $this->assertTrue(Schema::hasColumns('room_inspections', ['claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version']));
        $this->assertSame(1, (int) DB::scalar("SELECT COUNT(*) FROM pg_proc WHERE proname = 'hk_p17_inspection_claim_source_hash'"));
        $this->assertP17Unchanged($inspection, $p17);

        $migration->up();
        $this->assertTrue(Schema::hasTable('housekeeping_inspection_claim_reassignments'));
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
        $this->assertP17Unchanged($inspection, $p17);
    }

    public function test_fully_coherent_direct_postgresql_insert_succeeds_without_application_audit(): void
    {
        [, , $inspection] = $this->p19ClaimedInspection('P19-M-RAW-VALID');
        $p17 = $this->p17Snapshot($inspection);
        $this->p19MakeOriginalInactive();
        $payload = $this->rawPayload($inspection);

        $this->rawInsert($payload);

        $row = DB::table('housekeeping_inspection_claim_reassignments')->where('id', $payload['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($inspection->id, $row->room_inspection_id);
        $this->assertSame($this->housekeepingInspector->id, $row->original_claimant_id);
        $this->assertSame($this->p19Replacement->id, $row->replacement_claimant_id);
        $this->assertSame($this->p19Intervenor->id, $row->intervened_by);
        $this->assertSame('original_user_inactive_or_deleted', $row->original_ineligibility_code);
        $this->assertSame(1, (int) $row->evidence_version);
        $this->assertSame($row->occurred_at, $row->created_at);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'housekeeping_inspection_claim_reassigned')->count());
        $this->assertP17Unchanged($inspection, $p17);
    }

    public function test_direct_postgresql_insert_rejects_source_segregation_terminal_and_shape_violations(): void
    {
        [, $cleanerTask, $wrongOriginal] = $this->p19ClaimedInspection('P19-M-WRONG-ORIG');
        [, , $sameOriginal] = $this->p19ClaimedInspection('P19-M-SAME-ORIG');
        [, , $cleanerReplacement] = $this->p19ClaimedInspection('P19-M-CLEANER');
        [, , $passed] = $this->p19ClaimedInspection('P19-M-PASSED');
        [, , $failed] = $this->p19ClaimedInspection('P19-M-FAILED');
        [, , $wrongProperty] = $this->p19ClaimedInspection('P19-M-PROPERTY');
        [, , $badVersion] = $this->p19ClaimedInspection('P19-M-VERSION');
        [, , $badTimestamp] = $this->p19ClaimedInspection('P19-M-TIMESTAMP');
        $unclaimed = $this->unclaimedInspection('P19-M-NULL-P17');

        $lifecycle = app(HousekeepingCleaningInspectionReadinessLifecycleService::class);
        $passReason = 'Canonical terminal pass blocks later recovery insertion.';
        $lifecycle->confirmInspectionPass($this->housekeepingInspector, $passed->id, $passReason, 'password');
        $lifecycle->passInspection($this->housekeepingInspector, $passed->id, $passReason);
        $lifecycle->failInspection($this->housekeepingInspector, $failed->id, 'Canonical terminal failure blocks later recovery insertion.');
        $this->p19MakeOriginalInactive();

        $cases = [
            [$wrongOriginal, $this->rawPayload($wrongOriginal, ['original_claimant_id' => $this->p19Intervenor->id]), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT'],
            [$sameOriginal, $this->rawPayload($sameOriginal, ['replacement_claimant_id' => $this->housekeepingInspector->id]), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_ORIGINAL_PROHIBITED'],
            [$cleanerReplacement, $this->rawPayload($cleanerReplacement, ['replacement_claimant_id' => $cleanerTask->completed_by]), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_CLEANER_PROHIBITED'],
            [$unclaimed, $this->rawPayload($unclaimed, ['original_claimant_id' => $this->housekeepingInspector->id]), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT'],
            [$passed, $this->rawPayload($passed), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT'],
            [$failed, $this->rawPayload($failed), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT'],
            [$wrongProperty, $this->rawPayload($wrongProperty, ['property_id' => $this->otherProperty->id]), 'P0001', 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT'],
            [$badVersion, $this->rawPayload($badVersion, ['evidence_version' => 2]), '23514', 'hk_p19_reassignment_shape_check'],
            [$badTimestamp, $this->rawPayload($badTimestamp, ['created_at' => now()->addSecond()]), '23514', 'hk_p19_reassignment_shape_check'],
        ];

        foreach ($cases as [$inspection, $payload, $sqlState, $evidence]) {
            $p17 = $this->p17Snapshot($inspection);
            $this->assertRawWriteRejected(fn () => $this->rawInsert($payload), $sqlState, $evidence);
            $this->assertP17Unchanged($inspection, $p17);
        }
        $this->assertSame($this->housekeepingActor->id, $cleanerTask->completed_by);
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }

    public function test_direct_postgresql_insert_rejects_ineligible_replacement_user_membership_and_permission(): void
    {
        [, , $inactiveUser] = $this->p19ClaimedInspection('P19-M-R-INACTIVE');
        [, , $inactiveMembership] = $this->p19ClaimedInspection('P19-M-R-MEM-INACTIVE');
        [, , $missingMembership] = $this->p19ClaimedInspection('P19-M-R-MEM-MISSING');
        [, , $missingPermission] = $this->p19ClaimedInspection('P19-M-R-PERMISSION');
        $this->p19MakeOriginalInactive();

        $this->p19Replacement->update(['is_active' => false]);
        $this->assertRejectedWithP17($inactiveUser, $this->rawPayload($inactiveUser), 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_INVALID');
        $this->p19Replacement->update(['is_active' => true]);

        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->p19Replacement->id)->update(['status' => 'inactive']);
        $this->assertRejectedWithP17($inactiveMembership, $this->rawPayload($inactiveMembership), 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_INVALID');
        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->p19Replacement->id)->update(['status' => 'active']);

        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->p19Replacement->id)->delete();
        $this->assertRejectedWithP17($missingMembership, $this->rawPayload($missingMembership), 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_INVALID');
        $this->hkAttachProperty($this->p19Replacement, $this->property);

        $this->p19Replacement->revokePermissionTo(HousekeepingInspectionClaimService::CLAIM_PERMISSION);
        $this->assertRejectedWithP17($missingPermission, $this->rawPayload($missingPermission), 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_INVALID');
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }

    public function test_direct_postgresql_insert_rejects_ineligible_intervenor_user_membership_and_permission(): void
    {
        [, , $inactiveUser] = $this->p19ClaimedInspection('P19-M-I-INACTIVE');
        [, , $inactiveMembership] = $this->p19ClaimedInspection('P19-M-I-MEM-INACTIVE');
        [, , $missingMembership] = $this->p19ClaimedInspection('P19-M-I-MEM-MISSING');
        [, , $missingPermission] = $this->p19ClaimedInspection('P19-M-I-PERMISSION');
        $this->p19MakeOriginalInactive();

        $this->p19Intervenor->update(['is_active' => false]);
        $this->assertRejectedWithP17($inactiveUser, $this->rawPayload($inactiveUser), 'HK_P19_INSPECTION_CLAIM_RECOVERY_INTERVENOR_INVALID');
        $this->p19Intervenor->update(['is_active' => true]);

        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->p19Intervenor->id)->update(['status' => 'inactive']);
        $this->assertRejectedWithP17($inactiveMembership, $this->rawPayload($inactiveMembership), 'HK_P19_INSPECTION_CLAIM_RECOVERY_INTERVENOR_INVALID');
        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->p19Intervenor->id)->update(['status' => 'active']);

        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $this->p19Intervenor->id)->delete();
        $this->assertRejectedWithP17($missingMembership, $this->rawPayload($missingMembership), 'HK_P19_INSPECTION_CLAIM_RECOVERY_INTERVENOR_INVALID');
        $this->hkAttachProperty($this->p19Intervenor, $this->property);

        $this->p19Intervenor->revokePermissionTo(HousekeepingInspectionClaimRecoveryService::INTERVENE_PERMISSION);
        $this->assertRejectedWithP17($missingPermission, $this->rawPayload($missingPermission), 'HK_P19_INSPECTION_CLAIM_RECOVERY_INTERVENOR_INVALID');
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }

    public function test_direct_postgresql_insert_rejects_ineligibility_hash_uniqueness_and_committed_mutation_conflicts(): void
    {
        [, , $wrongCode] = $this->p19ClaimedInspection('P19-M-CODE');
        [, , $wrongHash] = $this->p19ClaimedInspection('P19-M-HASH');
        [, , $committed] = $this->p19ClaimedInspection('P19-M-UNIQUE-A');
        [, , $keyCollision] = $this->p19ClaimedInspection('P19-M-UNIQUE-B');
        $this->p19MakeOriginalInactive();

        $this->assertRejectedWithP17(
            $wrongCode,
            $this->rawPayload($wrongCode, ['original_ineligibility_code' => 'original_conduct_permission_missing']),
            'HK_P19_INSPECTION_CLAIM_RECOVERY_INELIGIBILITY_INVALID',
        );
        $this->assertRejectedWithP17(
            $wrongHash,
            $this->rawPayload($wrongHash, ['source_hash' => str_repeat('0', 64)]),
            'HK_P19_INSPECTION_CLAIM_RECOVERY_HASH_INVALID',
        );

        $p17 = $this->p17Snapshot($committed);
        $first = $this->rawPayload($committed);
        $this->rawInsert($first);
        $this->assertP17Unchanged($committed, $p17);

        $this->assertRawWriteRejected(
            fn () => $this->rawInsert($this->rawPayload($committed)),
            '23505',
            'hk_p19_reassignment_property_inspection_unique',
        );
        $this->assertP17Unchanged($committed, $p17);

        $collisionP17 = $this->p17Snapshot($keyCollision);
        $this->assertRawWriteRejected(
            fn () => $this->rawInsert($this->rawPayload($keyCollision, ['idempotency_key' => $first['idempotency_key']])),
            '23505',
            'hk_p19_reassignment_property_key_unique',
        );
        $this->assertP17Unchanged($keyCollision, $collisionP17);

        $this->assertRawWriteRejected(
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->where('id', $first['id'])->update(['reason' => 'malformed raw update']),
            'P0001',
            'HK_P19_INSPECTION_CLAIM_RECOVERY_IMMUTABLE',
        );
        $this->assertP17Unchanged($committed, $p17);
        $this->assertRawWriteRejected(
            fn () => DB::table('housekeeping_inspection_claim_reassignments')->where('id', $first['id'])->delete(),
            'P0001',
            'HK_P19_INSPECTION_CLAIM_RECOVERY_DELETE_PROHIBITED',
        );
        $this->assertP17Unchanged($committed, $p17);
        $this->assertSame(1, HousekeepingInspectionClaimReassignment::count());
    }

    private function assertRejectedWithP17(RoomInspection $inspection, array $payload, string $marker): void
    {
        $p17 = $this->p17Snapshot($inspection);
        $this->assertRawWriteRejected(fn () => $this->rawInsert($payload), 'P0001', $marker);
        $this->assertP17Unchanged($inspection, $p17);
    }

    private function assertRawWriteRejected(callable $operation, string $sqlState, string $evidence): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected direct PostgreSQL recovery evidence rejection.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, (string) $exception->getCode());
            $this->assertStringContainsString($evidence, $exception->getMessage());
        }
    }

    private function rawInsert(array $payload): void
    {
        $columns = implode(', ', self::RAW_COLUMNS);
        $placeholders = implode(', ', array_fill(0, count(self::RAW_COLUMNS), '?'));
        DB::insert(
            "INSERT INTO housekeeping_inspection_claim_reassignments ({$columns}) VALUES ({$placeholders})",
            array_map(fn (string $column) => $payload[$column], self::RAW_COLUMNS),
        );
    }

    private function rawPayload(RoomInspection $inspection, array $overrides = []): array
    {
        $inspection = $inspection->fresh();
        $task = CleaningTask::withoutGlobalScopes()->findOrFail($inspection->cleaning_task_id);
        $occurredAt = now();
        $payload = array_merge([
            'id' => (string) Str::ulid(),
            'property_id' => $inspection->property_id,
            'room_inspection_id' => $inspection->id,
            'original_claimant_id' => $inspection->supervisor_id,
            'replacement_claimant_id' => $this->p19Replacement->id,
            'intervened_by' => $this->p19Intervenor->id,
            'original_ineligibility_code' => 'original_user_inactive_or_deleted',
            'reason' => 'Test-only coherent direct PostgreSQL recovery evidence.',
            'idempotency_key' => 'p19-raw-'.Str::uuid(),
            'source_hash' => null,
            'evidence_version' => 1,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ], $overrides);

        if (! array_key_exists('source_hash', $overrides)) {
            $claimedAt = DB::scalar(
                <<<'SQL'
                    SELECT to_char(claimed_at, 'YYYY-MM-DD"T"HH24:MI:SS.US')
                    FROM room_inspections
                    WHERE id = ?
                SQL,
                [$inspection->id],
            );
            $payload['source_hash'] = DB::scalar(
                'SELECT hk_p19_inspection_claim_reassignment_source_hash(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $payload['evidence_version'],
                    $payload['property_id'],
                    $payload['room_inspection_id'],
                    $inspection->supervisor_id,
                    $claimedAt,
                    $inspection->claim_idempotency_key,
                    $inspection->claim_source_hash,
                    $inspection->claim_evidence_version,
                    $task->id,
                    $task->completed_by,
                    $payload['original_claimant_id'],
                    $payload['replacement_claimant_id'],
                    $payload['intervened_by'],
                    $payload['original_ineligibility_code'],
                    $payload['reason'],
                    $payload['idempotency_key'],
                ],
            );
        }

        return $payload;
    }

    private function unclaimedInspection(string $roomNumber): RoomInspection
    {
        $room = Room::findOrFail($this->hkCleanRoom($this->property, $roomNumber));
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'task_code' => 'TASK-'.$roomNumber,
            'task_type' => 'checkout_cleaning',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'completed_by' => $this->housekeepingActor->id,
        ]);

        return RoomInspection::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'cleaning_task_id' => $task->id,
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
        ]);
    }

    private function p17Snapshot(RoomInspection $inspection): array
    {
        $inspection = $inspection->fresh();

        return collect(self::P17_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => $inspection->getRawOriginal($field)])
            ->all();
    }

    private function assertP17Unchanged(RoomInspection $inspection, array $expected): void
    {
        $this->assertSame($expected, $this->p17Snapshot($inspection));
    }
}

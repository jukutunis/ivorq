<?php

namespace Tests\Postgres\Foundation\Authorization;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Foundation\Authorization\Enums\FirstTrustRunStatus;
use Modules\Foundation\Authorization\Models\FirstTrustRun;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Postgres\Foundation\Authorization\Support\FirstTrustTestCase;
use Throwable;

class FirstTrustRecoveryTest extends FirstTrustTestCase
{
    public function test_ambiguous_reserve_recovers_the_same_winning_execution(): void
    {
        $fake = $this->fake(['reserve_timeout_after_mutation_once' => true]);

        $result = $this->establish();

        $this->assertSame('COMPLETED', $result->state);
        $this->assertSame($fake->snapshot()['execution_id'], $result->executionId);
        $this->assertFoundationCounts(1);
    }

    public function test_reserve_owned_by_a_different_execution_is_rejected_without_takeover(): void
    {
        $fake = $this->fake([
            'reserve_other_execution_once' => true,
            'other_execution_id' => 'ftx-01JTESTOTHEREXECUTION0000',
        ]);

        try {
            $this->establish();
            $this->fail('A losing execution must not take over the reservation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ALREADY_RESERVED_OTHER_EXECUTION', $exception->getMessage());
        }

        $this->assertSame('ftx-01JTESTOTHEREXECUTION0000', $fake->snapshot()['execution_id']);
        $this->assertNoFoundationMutation();
    }

    public function test_ambiguous_consume_reconciles_external_consumption_exactly_once(): void
    {
        $fake = $this->fake(['consume_timeout_after_mutation_once' => true]);

        $result = $this->establish();

        $this->assertSame('COMPLETED', $result->state);
        $this->assertSame('CONSUMED', $fake->snapshot()['state']);
        $this->assertFoundationCounts(1);
        $this->assertSame(1, DB::table('owner_activation_tokens')->count());
    }

    public function test_consume_still_reserved_is_retried_once_for_the_exact_execution(): void
    {
        $fake = $this->fake(['keep_reserved_once' => true]);

        $result = $this->establish();

        $this->assertSame('COMPLETED', $result->state);
        $this->assertSame('CONSUMED', $fake->snapshot()['state']);
        $this->assertFoundationCounts(1);
    }

    public function test_external_consumed_local_in_progress_recovery_completes_without_recreating_foundation(): void
    {
        $fake = $this->fake([
            'consume_timeout_after_mutation_once' => true,
            'state_unavailable_after_consume_failure' => true,
        ]);
        try {
            $this->establish();
            $this->fail('Unavailable reconciliation must preserve IN_PROGRESS.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AUTHORITY_STATE_UNAVAILABLE', $exception->getMessage());
        }

        $run = FirstTrustRun::query()->sole();
        $identities = [$run->user_id, $run->company_id, $run->property_id, $run->owner_activation_id];
        $this->assertSame(FirstTrustRunStatus::InProgress, $run->status);
        $this->assertNotNull($run->foundation_committed_at);
        $this->assertFoundationCounts(1);
        $this->assertSame(0, DB::table('owner_activation_tokens')->count());
        $this->assertSame('CONSUMED', $fake->snapshot()['state']);

        $fake->configure([
            'state_unavailable' => false,
            'authorization_expires_at' => $run->reservation_recovery_deadline->addDays(2)->toAtomString(),
        ]);
        $result = $this->establish();

        $recovered = FirstTrustRun::query()->sole();
        $this->assertSame('COMPLETED', $result->state);
        $this->assertSame($identities, [
            $recovered->user_id,
            $recovered->company_id,
            $recovered->property_id,
            $recovered->owner_activation_id,
        ]);
        $this->assertFoundationCounts(1);
        $this->assertSame(1, DB::table('owner_activation_tokens')->count());
    }

    public function test_completed_exact_retry_is_mutation_free_and_does_not_redeliver(): void
    {
        $this->fake();
        $first = $this->establish();
        $before = FirstTrustRun::query()->sole();
        $timestamps = [$before->created_at?->format('c'), $before->updated_at?->format('c'), $before->completed_at?->format('c')];
        $tokenCount = DB::table('owner_activation_tokens')->count();
        Notification::assertCount(1);

        $second = $this->establish();

        $after = FirstTrustRun::query()->sole();
        $this->assertSame($first->runId, $second->runId);
        $this->assertSame('not_attempted', $second->activationDeliveryState);
        $this->assertSame($timestamps, [$after->created_at?->format('c'), $after->updated_at?->format('c'), $after->completed_at?->format('c')]);
        $this->assertSame($tokenCount, DB::table('owner_activation_tokens')->count());
        Notification::assertCount(1);
        $this->assertFoundationCounts(1);
    }

    public function test_changed_retry_is_rejected_without_mutation(): void
    {
        $this->fake();
        $this->establish();
        $run = FirstTrustRun::query()->sole();
        $updatedAt = $run->updated_at?->format('c');

        try {
            $this->establish($this->request(['ownerEmail' => 'different@example.test']));
            $this->fail('Changed security input must not be adopted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('REQUEST_FINGERPRINT_MISMATCH', $exception->getMessage());
        }

        $this->assertSame($updatedAt, FirstTrustRun::query()->sole()->updated_at?->format('c'));
        $this->assertFoundationCounts(1);
        $this->assertSame(1, DB::table('owner_activation_tokens')->count());
    }

    public function test_expired_recovery_deadline_preserves_the_in_progress_foundation(): void
    {
        $fake = $this->fake([
            'consume_timeout_before_mutation_once' => true,
            'state_unavailable_after_consume_failure' => true,
        ]);
        try {
            $this->establish();
        } catch (RuntimeException) {
            // Expected reconciliation interruption.
        }
        $run = FirstTrustRun::query()->sole();
        $fake->configure([
            'state_unavailable' => false,
            'authorization_expires_at' => $run->reservation_recovery_deadline->addDays(2)->toAtomString(),
        ]);

        CarbonImmutable::setTestNow($run->reservation_recovery_deadline->addSecond());
        try {
            $this->establish();
            $this->fail('Expired recovery must require owner governance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AUTHORITY_RECOVERY_REQUIRED', $exception->getMessage());
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame(FirstTrustRunStatus::InProgress, $run->fresh()->status);
        $this->assertFoundationCounts(1);
        $this->assertSame(0, DB::table('owner_activation_tokens')->count());
    }

    public function test_expired_commit_lease_rejects_before_local_foundation(): void
    {
        $now = now();
        $this->fake([
            'state' => 'RESERVED',
            'execution_id' => 'ftx-01JEXPIREDCOMMITLEASE00',
            'reservation_reference' => 'reservation-expired-commit',
            'reservation_fingerprint' => str_repeat('a', 64),
            'reserved_at' => $now->copy()->subHours(2)->toAtomString(),
            'commit_deadline' => $now->copy()->subHour()->toAtomString(),
            'recovery_deadline' => $now->copy()->addHour()->toAtomString(),
        ]);

        try {
            $this->establish();
            $this->fail('Expired commit lease must not create local state.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_COMMIT_LEASE_EXPIRED', $exception->getMessage());
        }
        $this->assertNoFoundationMutation();
    }

    public function test_external_consumed_without_local_foundation_is_rejected(): void
    {
        $now = now();
        $this->fake([
            'state' => 'CONSUMED',
            'execution_id' => 'ftx-01JEXTERNALONLY0000000',
            'reservation_reference' => 'reservation-external-only',
            'reservation_fingerprint' => str_repeat('b', 64),
            'reserved_at' => $now->copy()->subMinute()->toAtomString(),
            'commit_deadline' => $now->copy()->addMinutes(14)->toAtomString(),
            'recovery_deadline' => $now->copy()->addDay()->toAtomString(),
            'consumption_reference' => 'consumption-external-only',
            'consumption_fingerprint' => str_repeat('c', 64),
            'consumed_at' => $now->toAtomString(),
        ]);

        try {
            $this->establish();
            $this->fail('External consumption without local evidence must not recreate a universe.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE', $exception->getMessage());
        }
        $this->assertNoFoundationMutation();
    }

    public function test_in_progress_without_complete_foundation_is_a_terminal_divergence(): void
    {
        $run = $this->seedRunWithoutFoundation();

        try {
            $this->establish();
            $this->fail('A partial durable foundation must never be invented during recovery.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE', $exception->getMessage());
        }

        $this->assertSame(FirstTrustRunStatus::InProgress, $run->fresh()->status);
        $this->assertSeededRunIsTheOnlyLocalMutation();
    }

    public function test_failed_local_run_is_terminal_and_cannot_be_reopened(): void
    {
        $run = $this->seedRunWithoutFoundation();
        $run->forceFill([
            'status' => FirstTrustRunStatus::Failed,
            'failed_at' => now(),
            'failure_code' => 'TEST_TERMINAL_FAILURE',
        ])->save();

        try {
            $this->establish();
            $this->fail('A failed first-trust run must remain terminal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_LOCAL_FAILED_TERMINAL', $exception->getMessage());
        }

        $this->assertSame(FirstTrustRunStatus::Failed, $run->fresh()->status);
        $this->assertSeededRunIsTheOnlyLocalMutation();
    }

    public static function rollbackFaults(): array
    {
        return [
            'Company insert' => ['companies', 'INSERT'],
            'Property insert' => ['properties', 'INSERT'],
            'User insert' => ['users', 'INSERT'],
            'membership insert' => ['property_user', 'INSERT'],
            'inviteExistingOwner' => ['owner_activations', 'INSERT'],
            'ledger result binding' => ['first_trust_runs', 'UPDATE'],
        ];
    }

    #[DataProvider('rollbackFaults')]
    public function test_foundation_fault_rolls_back_every_local_write(string $table, string $operation): void
    {
        $function = 'b5a2b_fault_'.strtolower($table).'_'.strtolower($operation);
        $trigger = 'trg_'.$function;
        DB::unprepared("DROP FUNCTION IF EXISTS {$function}() CASCADE");
        DB::unprepared("CREATE FUNCTION {$function}() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'B5A2B_TEST_FAULT'; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}() ");
        $fake = $this->fake();

        try {
            $this->establish();
            $this->fail("{$table} {$operation} fault must abort the foundation transaction.");
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('RESERVED', $fake->snapshot()['state']);
        $this->assertNoFoundationMutation();
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    private function seedRunWithoutFoundation(): FirstTrustRun
    {
        $reservedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $executionId = 'ftx-01JSEEDEDRECOVERY0000000';
        $authorizationId = 'authorization-seeded-001';
        $reservationReference = 'reservation-seeded-001';
        $reservationFingerprint = str_repeat('b', 64);
        $commitDeadline = $reservedAt->addMinutes(15);
        $recoveryDeadline = $reservedAt->addDay();

        $run = new FirstTrustRun;
        $run->forceFill([
            'environment' => 'rehearsal',
            'installation_id' => 'ivorq-test-installation',
            'status' => FirstTrustRunStatus::InProgress,
            'execution_id' => $executionId,
            'authorization_id' => $authorizationId,
            'authority_reference' => 'authority/rehearsal/v1',
            'authority_issuer' => 'https://authority.example.test/rehearsal',
            'request_fingerprint' => $this->requestFingerprint(),
            'canonical_sha' => $this->releaseSha,
            'verifier_key_id' => 'test-key-001',
            'verifier_bundle_fingerprint' => hash_file('sha256', $this->bundlePath),
            'reservation_reference' => $reservationReference,
            'reservation_fingerprint' => $reservationFingerprint,
            'reservation_reserved_at' => $reservedAt,
            'reservation_commit_deadline' => $commitDeadline,
            'reservation_recovery_deadline' => $recoveryDeadline,
            'started_at' => $reservedAt,
        ])->save();

        $this->fake([
            'authorization_id' => $authorizationId,
            'state' => 'RESERVED',
            'execution_id' => $executionId,
            'reservation_reference' => $reservationReference,
            'reservation_fingerprint' => $reservationFingerprint,
            'reserved_at' => $reservedAt->toAtomString(),
            'commit_deadline' => $commitDeadline->toAtomString(),
            'recovery_deadline' => $recoveryDeadline->toAtomString(),
        ]);

        return $run;
    }

    private function assertSeededRunIsTheOnlyLocalMutation(): void
    {
        $this->assertSame(1, DB::table('first_trust_runs')->count());
        foreach (['users', 'companies', 'properties', 'property_user', 'owner_activations'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('owner_activation_tokens')->count());
    }
}

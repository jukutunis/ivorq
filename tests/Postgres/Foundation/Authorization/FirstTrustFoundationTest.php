<?php

namespace Tests\Postgres\Foundation\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Foundation\Authentication\Notifications\OwnerActivationNotification;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Enums\FirstTrustRunStatus;
use Modules\Foundation\Authorization\Models\FirstTrustRun;
use Modules\Foundation\Authorization\Services\FirstTrustActivationDeliveryService;
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Postgres\Foundation\Authorization\Support\FirstTrustTestCase;

class FirstTrustFoundationTest extends FirstTrustTestCase
{
    public function test_happy_path_creates_exact_atomic_foundation_and_delivers_after_completion(): void
    {
        $this->fake();

        $result = $this->establish();

        $this->assertSame('COMPLETED', $result->state);
        $this->assertSame('sent', $result->activationDeliveryState);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result->completionEvidenceFingerprint);
        $this->assertFoundationCounts(1);

        $user = DB::table('users')->sole();
        $this->assertSame('Edi Owner', $user->name);
        $this->assertSame('owner@example.test', $user->email);
        $this->assertNull($user->password);
        $this->assertFalse((bool) $user->is_active);
        $this->assertFalse((bool) $user->is_system_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->department_id);
        $this->assertNull($user->position_id);
        $this->assertNull($user->employee_id);

        $company = DB::table('companies')->sole();
        $property = DB::table('properties')->sole();
        $membership = DB::table('property_user')->sole();
        $activation = DB::table('owner_activations')->sole();
        $run = FirstTrustRun::query()->sole();

        $this->assertTrue((bool) $company->is_active);
        $this->assertSame('ivorq-test-company', $company->slug);
        $this->assertTrue((bool) $property->is_active);
        $this->assertSame($company->id, $property->company_id);
        $this->assertSame('TST_01', $property->code);
        $this->assertSame('IDR', $property->currency);
        $this->assertSame('Asia/Makassar', $property->timezone);
        $this->assertSame('active', $membership->status);
        $this->assertTrue((bool) $membership->is_default);
        $this->assertSame('INVITED', $activation->status);
        $this->assertSame('installation-owner', $activation->pending_role_name);
        $this->assertSame(FirstTrustRunStatus::Completed, $run->status);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame($company->id, $run->company_id);
        $this->assertSame($property->id, $run->property_id);
        $this->assertSame($activation->id, $run->owner_activation_id);

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('user_sessions')->count());
        $this->assertSame(0, DB::table('user_devices')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
        $this->assertSame(0, DB::table('model_has_roles')->count());
        $this->assertSame(0, DB::table('model_has_permissions')->count());
        $this->assertSame(0, DB::table('property_bootstrap_provisioning_runs')->count());
        $this->assertSame(0, DB::table('property_business_dates')->count());
        $this->assertSame(0, DB::table('gl_financial_periods')->count());
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->assertSame(0, DB::table('gl_journal_entries')->count());
        $this->assertSame(0, DB::table('payment_executions')->count());
        $this->assertSame(0, DB::table('cost_ledger_entries')->count());

        $this->assertSame(1, DB::table('owner_activation_tokens')->count());
        Notification::assertSentOnDemand(OwnerActivationNotification::class);
    }

    public function test_default_unconfigured_authority_fails_before_any_local_mutation(): void
    {
        $this->app->forgetInstance(FirstTrustAuthority::class);
        $this->app->forgetInstance(FirstTrustOrchestrator::class);

        try {
            $this->establish();
            $this->fail('The default authority must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_AUTHORITY_UNCONFIGURED', $exception->getMessage());
        }

        $this->assertNoFoundationMutation();
    }

    public function test_invalid_bundle_and_release_binding_fail_before_authority_or_local_mutation(): void
    {
        $this->fake(['verify_throws' => true]);
        config(['first-trust.authority.verification_bundle_path' => $this->bundlePath.'.missing']);

        try {
            $this->establish();
            $this->fail('Missing verification bundle must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_VERIFICATION_BUNDLE_INVALID', $exception->getMessage());
        }
        $this->assertNoFoundationMutation();

        config([
            'first-trust.authority.verification_bundle_path' => $this->bundlePath,
            'first-trust.release_sha' => str_repeat('a', 40),
        ]);
        try {
            $this->establish();
            $this->fail('Mismatched release SHA must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_RELEASE_SHA_MISMATCH', $exception->getMessage());
        }
        $this->assertNoFoundationMutation();
    }

    public static function authorityFailures(): array
    {
        return [
            'provider verification exception' => [['verify_throws' => true], 'FIRST_TRUST_AUTHORIZATION_VERIFICATION_FAILED'],
            'unavailable initial state' => [['state_unavailable' => true], 'AUTHORITY_STATE_UNAVAILABLE'],
            'invalid authorization projection' => [['verified_key_id' => 'invalid key id'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'expired authority state' => [['state' => 'EXPIRED'], 'FIRST_TRUST_AUTHORIZATION_EXPIRED'],
            'revoked authority state' => [['state' => 'REVOKED'], 'FIRST_TRUST_AUTHORIZATION_REVOKED'],
            'environment mismatch' => [['binding_environment' => 'OPERATIONAL'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'installation mismatch' => [['binding_installation_id' => 'different-installation'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'canonical SHA mismatch' => [['binding_canonical_sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'request fingerprint mismatch' => [['binding_request_fingerprint' => str_repeat('c', 64)], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'authority reference mismatch' => [['authority_reference' => 'authority/different/v1'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'authority issuer mismatch' => [['authority_issuer' => 'https://different.example.test'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'authority audience mismatch' => [['authority_audience' => 'different-audience'], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
            'verification bundle mismatch' => [['verifier_bundle_fingerprint' => str_repeat('e', 64)], 'FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID'],
        ];
    }

    #[DataProvider('authorityFailures')]
    public function test_authority_failures_are_sanitized_and_cause_zero_local_mutation(
        array $authorityState,
        string $expectedFailure,
    ): void {
        $this->fake($authorityState);

        try {
            $this->establish();
            $this->fail('Authority failure must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedFailure, $exception->getMessage());
        }

        $this->assertNoFoundationMutation();
    }

    public function test_membership_and_completion_fingerprints_match_frozen_vectors(): void
    {
        $orchestrator = app(FirstTrustOrchestrator::class);
        $utc = new DateTimeZone('UTC');
        $joinedAt = new DateTimeImmutable('2026-01-02T03:04:05Z', $utc);
        $userId = '01USER0000000000000000000000';
        $propertyId = '01PROPERTY000000000000000000';

        $membershipFingerprint = (new ReflectionMethod($orchestrator, 'membershipFingerprint'))
            ->invoke($orchestrator, $userId, $propertyId, $joinedAt);
        $this->assertSame(
            'c8e09a3c27046f75afe8f8ebb4156e4223b09a7ec928b19c9c37e7aa2b78f74c',
            $membershipFingerprint,
        );

        $run = new FirstTrustRun;
        $run->forceFill([
            'id' => '01RUN0000000000000000000000',
            'environment' => 'rehearsal',
            'installation_id' => 'installation-001',
            'execution_id' => 'ftx-01EXECUTION000000000000000',
            'authorization_id' => 'authorization-001',
            'authority_reference' => 'authority/rehearsal/v1',
            'authority_issuer' => 'https://authority.example.test/rehearsal',
            'request_fingerprint' => str_repeat('d', 64),
            'canonical_sha' => str_repeat('a', 40),
            'verifier_key_id' => 'key-001',
            'verifier_bundle_fingerprint' => str_repeat('e', 64),
            'reservation_reference' => 'reservation-001',
            'reservation_fingerprint' => str_repeat('b', 64),
            'reservation_reserved_at' => $joinedAt,
            'reservation_commit_deadline' => new DateTimeImmutable('2026-01-02T03:19:05Z', $utc),
            'reservation_recovery_deadline' => new DateTimeImmutable('2026-01-03T03:04:05Z', $utc),
            'user_id' => $userId,
            'company_id' => '01COMPANY000000000000000000',
            'property_id' => $propertyId,
            'membership_user_id' => $userId,
            'membership_property_id' => $propertyId,
            'membership_fingerprint' => $membershipFingerprint,
            'owner_activation_id' => '01ACTIVATION0000000000000000',
            'foundation_committed_at' => new DateTimeImmutable('2026-01-02T03:05:00Z', $utc),
        ]);
        $binding = new FirstTrustExpectedBinding(
            'REHEARSAL',
            'installation-001',
            str_repeat('d', 64),
            str_repeat('a', 40),
        );
        $externalConsumedAt = new DateTimeImmutable('2026-01-02T03:10:00Z', $utc);
        $consumption = new VerifiedFirstTrustConsumption(
            'authorization-001',
            'authority/rehearsal/v1',
            'https://authority.example.test/rehearsal',
            'ftx-01EXECUTION000000000000000',
            $binding,
            'reservation-001',
            $joinedAt,
            'consumption-001',
            str_repeat('c', 64),
            $externalConsumedAt,
            'key-001',
            str_repeat('e', 64),
        );

        $completionFingerprint = (new ReflectionMethod($orchestrator, 'completionFingerprint'))
            ->invoke($orchestrator, $run, $consumption, $externalConsumedAt);
        $this->assertSame(
            '8132e6fac073004d60eae67f5ffe255c0e7a759f19d23a35fdeeb6b13b87fea8',
            $completionFingerprint,
        );
    }

    public function test_delivery_is_rejected_while_run_is_in_progress(): void
    {
        $fake = $this->fake([
            'consume_timeout_before_mutation_once' => true,
            'state_unavailable_after_consume_failure' => true,
        ]);
        try {
            $this->establish();
            $this->fail('Unreconciled consumption must not complete.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AUTHORITY_STATE_UNAVAILABLE', $exception->getMessage());
        }
        $run = FirstTrustRun::query()->sole();
        $this->assertSame(FirstTrustRunStatus::InProgress, $run->status);
        $this->assertSame('RESERVED', $fake->snapshot()['state']);
        $this->assertSame(0, DB::table('owner_activation_tokens')->count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FIRST_TRUST_ACTIVATION_DELIVERY_BEFORE_COMPLETION');
        app(FirstTrustActivationDeliveryService::class)->deliver($run);
    }

    public function test_mail_failure_keeps_completed_invited_state_and_exposes_only_recovery_state(): void
    {
        $this->fake();
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('RAW-MAIL-SECRET'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $result = $this->establish();

        $this->assertSame('COMPLETED', $result->state);
        $this->assertSame('failed_recovery_available', $result->activationDeliveryState);
        $this->assertSame('INVITED', DB::table('owner_activations')->value('status'));
        $this->assertSame(1, DB::table('owner_activation_tokens')->count());
    }

    public function test_raw_authorization_is_absent_from_relevant_persistence(): void
    {
        $this->fake();
        $this->establish();

        $marker = self::RAW_AUTHORIZATION;
        $queries = [
            'SELECT count(*) FROM first_trust_runs WHERE row_to_json(first_trust_runs)::text LIKE ?',
            'SELECT count(*) FROM audit_logs WHERE row_to_json(audit_logs)::text LIKE ?',
            'SELECT count(*) FROM activity_logs WHERE row_to_json(activity_logs)::text LIKE ?',
            'SELECT count(*) FROM identity_security_events WHERE row_to_json(identity_security_events)::text LIKE ?',
            'SELECT count(*) FROM owner_activation_tokens WHERE row_to_json(owner_activation_tokens)::text LIKE ?',
        ];
        foreach ($queries as $query) {
            $this->assertSame(0, (int) DB::scalar($query, ['%'.$marker.'%']));
        }
    }
}

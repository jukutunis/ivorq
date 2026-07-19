<?php

namespace Tests\Postgres\Operations\NightAudit;

use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Modules\Operations\NightAudit\Services\NightAuditBusinessDateDependencyService;
use Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService;
use Modules\Operations\NightAudit\Services\NightAuditLockProjectionService;
use Modules\Operations\NightAudit\Services\NightAuditRunAbortService;
use Modules\Operations\NightAudit\Services\NightAuditRunStartService;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class NightAuditCheckoutConcurrencyFoundationTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Company $company;
    private Property $property;
    private User $actor;
    private PropertyBusinessDate $businessDate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
            NightAuditAuthorizationService::ABORT_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->createAuthorizedContext();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_lock_service_requires_active_postgresql_transaction_and_declares_driver_guard(): void
    {
        try {
            app(PropertyBusinessDateOperationalLockService::class)->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $this->fail('Lock service must reject use outside a transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame(PropertyBusinessDateOperationalLockService::ERROR_REQUIRES_ACTIVE_TRANSACTION, $exception->getMessage());
        }

        $source = file_get_contents(base_path('Modules/Foundation/Property/Services/PropertyBusinessDateOperationalLockService.php'));
        $this->assertStringContainsString("getDriverName() !== 'pgsql'", $source);
        $this->assertStringContainsString(PropertyBusinessDateOperationalLockService::ERROR_POSTGRESQL_REQUIRED, $source);
    }

    public function test_lock_service_locks_property_before_business_date_and_returns_server_context(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'for update')) {
                $queries[] = $query->sql;
            }
        });

        $context = DB::transaction(fn () => app(PropertyBusinessDateOperationalLockService::class)
            ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence()));

        $this->assertStringContainsString('from "properties"', $queries[0] ?? '');
        $this->assertStringContainsString('from "property_business_dates"', $queries[1] ?? '');
        $this->assertSame($this->company->id, $context->company_id);
        $this->assertSame($this->property->id, $context->property_id);
        $this->assertSame($this->businessDate->id, $context->property_business_date_id);
        $this->assertSame('2026-07-17', $context->business_date);
        $this->assertSame('Asia/Makassar', $context->property_timezone);
        $this->assertSame($this->actor->id, $context->opened_by);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $context->source_fingerprint);
        $this->assertIsInt($context->postgres_backend_pid);
        $this->assertGreaterThan(0, $context->postgres_backend_pid);
        $this->assertIsString($context->postgres_transaction_id);
        $this->assertNotSame('', $context->postgres_transaction_id);
        $this->assertSame([], array_intersect([
            'guest_id', 'reservation_id', 'room_id', 'folio_id', 'cashier_session_id', 'front_desk_status', 'can_execute',
        ], array_keys($context->toArray())));
    }

    public function test_lock_service_fails_closed_for_property_and_business_date_context_changes(): void
    {
        foreach ([
            'inactive_property' => fn () => $this->property->forceFill(['is_active' => false])->save(),
            'cross_company' => function (): void {
                $other = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
                $this->property->forceFill(['company_id' => $other->id])->save();
            },
        ] as $case => $mutate) {
            $this->createAuthorizedContext();
            $evidence = $this->businessDateEvidence();
            $mutate();

            try {
                DB::transaction(fn () => app(PropertyBusinessDateOperationalLockService::class)
                    ->acquire($this->company->id, $this->property->id, $evidence));
                $this->fail("{$case} must fail closed.");
            } catch (\Throwable $exception) {
                $this->assertContains($exception->getMessage(), [
                    PropertyBusinessDateOperationalLockService::ERROR_CONTEXT_CHANGED,
                    PropertyBusinessDateOperationalLockService::ERROR_BUSINESS_DATE_LOCK_UNAVAILABLE,
                ], $case);
            }
        }
    }

    public function test_lock_service_detects_business_date_identity_date_timezone_and_opening_evidence_mismatch(): void
    {
        foreach ([
            'property_business_date_id' => (string) Str::ulid(),
            'business_date' => '2026-07-18',
            'property_timezone' => 'UTC',
            'opened_by' => (string) Str::ulid(),
            'opened_at' => Carbon::parse('2026-07-17 02:00:00', 'UTC')->toISOString(),
        ] as $field => $value) {
            $evidence = array_merge($this->businessDateEvidence(), [$field => $value]);

            try {
                DB::transaction(fn () => app(PropertyBusinessDateOperationalLockService::class)
                    ->acquire($this->company->id, $this->property->id, $evidence));
                $this->fail("{$field} mismatch must fail closed.");
            } catch (\Throwable $exception) {
                $this->assertContains($exception->getMessage(), [
                    PropertyBusinessDateOperationalLockService::ERROR_CONTEXT_CHANGED,
                    PropertyBusinessDateOperationalLockService::ERROR_BUSINESS_DATE_LOCK_UNAVAILABLE,
                ]);
            }
        }
    }

    public function test_lock_timeout_mapping_is_narrow_and_unknown_query_errors_are_not_normalized(): void
    {
        $source = file_get_contents(base_path('Modules/Foundation/Property/Services/PropertyBusinessDateOperationalLockService.php'));
        $this->assertStringContainsString('SET LOCAL lock_timeout', $source);
        $this->assertStringContainsString("private const LOCK_TIMEOUT = '5s'", $source);
        $this->assertStringContainsString("LOCK_TIMEOUT_SQLSTATE = '55P03'", $source);
        $this->assertStringContainsString('throw $exception;', $source);
        $this->assertStringNotContainsString("'40P01' =>", $source);
        $this->assertStringNotContainsString("'40001' =>", $source);
        $this->assertStringNotContainsString("'57014' =>", $source);
    }

    public function test_guard_requires_transaction_same_context_and_returns_clear_attestation_whitelist(): void
    {
        $context = DB::transaction(fn () => app(PropertyBusinessDateOperationalLockService::class)
            ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence()));

        try {
            app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
            $this->fail('Guard must reject use outside a transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NightAuditCheckoutConcurrencyGuardService::ERROR_REQUIRES_ACTIVE_TRANSACTION, $exception->getMessage());
        }

        DB::transaction(function (): void {
            $context = app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $guard = app(NightAuditCheckoutConcurrencyGuardService::class);
            $clear = $guard->attest($context);
            $second = $guard->attest($context);

            $this->assertSame($this->attestationKeys(), array_keys($clear->toArray()));
            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::VERSION, $clear->attestation_version);
            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR, $clear->status);
            $this->assertTrue($clear->transaction_bound);
            $this->assertFalse($clear->close_lock_active);
            $this->assertSame($this->property->id, $clear->property_id);
            $this->assertSame($clear->source_fingerprint, $second->source_fingerprint);
            $this->assertArrayNotHasKey('night_audit_run_id', $clear->toArray());
            $this->assertArrayNotHasKey('started_by', $clear->toArray());
            $this->assertArrayNotHasKey('postgres_backend_pid', $clear->toArray());
            $this->assertArrayNotHasKey('postgres_transaction_id', $clear->toArray());
        });
    }

    public function test_stale_issued_context_is_rejected_in_new_transaction_on_same_backend_before_night_audit_query(): void
    {
        $before = $this->allCounts();
        $nightAuditQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$nightAuditQueries): void {
            if (str_contains(strtolower($query->sql), 'night_audit_runs')) {
                $nightAuditQueries[] = $query->sql;
            }
        });

        DB::beginTransaction();
        try {
            $context = app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $first = app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
            $transactionA = $context->postgres_transaction_id;
            $backendA = $context->postgres_backend_pid;
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $nightAuditQueries = [];
        DB::beginTransaction();
        try {
            $proofB = DB::selectOne(
                'SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id'
            );
            $this->assertSame($backendA, (int) $proofB->backend_pid);
            $this->assertNotSame($transactionA, (string) $proofB->transaction_id);

            try {
                app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
                $this->fail('Context issued in transaction A must be rejected in transaction B.');
            } catch (DomainException $exception) {
                $this->assertSame(NightAuditCheckoutConcurrencyGuardService::ERROR_INVALID_CONTEXT, $exception->getMessage());
            }

            $this->assertSame([], $nightAuditQueries);

            $freshContext = app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $fresh = app(NightAuditCheckoutConcurrencyGuardService::class)->attest($freshContext);
            $this->assertNotSame($first->source_fingerprint, $fresh->source_fingerprint);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $this->assertSame($before, $this->allCounts());
    }

    public function test_manually_constructed_context_is_rejected_before_night_audit_query(): void
    {
        $before = $this->allCounts();
        $nightAuditQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$nightAuditQueries): void {
            if (str_contains(strtolower($query->sql), 'night_audit_runs')) {
                $nightAuditQueries[] = $query->sql;
            }
        });

        DB::transaction(function () use (&$nightAuditQueries): void {
            $issued = app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $forged = new PropertyBusinessDateOperationalLockContext(
                company_id: $issued->company_id,
                property_id: $issued->property_id,
                property_business_date_id: $issued->property_business_date_id,
                business_date: $issued->business_date,
                property_timezone: $issued->property_timezone,
                opened_by: $issued->opened_by,
                opened_at: $issued->opened_at,
                source_fingerprint: $issued->source_fingerprint,
                postgres_backend_pid: $issued->postgres_backend_pid,
                postgres_transaction_id: $issued->postgres_transaction_id,
                lock_acquired_at: $issued->lock_acquired_at,
            );

            $this->assertSame($issued->toArray(), $forged->toArray());

            try {
                app(NightAuditCheckoutConcurrencyGuardService::class)->attest($forged);
                $this->fail('Field-identical but unissued context must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame(NightAuditCheckoutConcurrencyGuardService::ERROR_INVALID_CONTEXT, $exception->getMessage());
            }

            $this->assertSame([], $nightAuditQueries);
        });

        $this->assertSame($before, $this->allCounts());
    }

    public function test_rolled_back_savepoint_invalidates_retained_context_before_night_audit_query(): void
    {
        $before = $this->allCounts();
        $nightAuditQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$nightAuditQueries): void {
            if (str_contains(strtolower($query->sql), 'night_audit_runs')) {
                $nightAuditQueries[] = $query->sql;
            }
        });

        DB::beginTransaction();
        try {
            $context = null;
            $nestedProof = null;

            try {
                DB::transaction(function () use (&$context, &$nestedProof): void {
                    $context = app(PropertyBusinessDateOperationalLockService::class)
                        ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
                    app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
                    $nestedProof = $this->postgresCapabilityProof();

                    throw new RuntimeException('NA_A2_FORCE_SAVEPOINT_ROLLBACK');
                });
                $this->fail('Nested transaction must roll back to its savepoint.');
            } catch (RuntimeException $exception) {
                $this->assertSame('NA_A2_FORCE_SAVEPOINT_ROLLBACK', $exception->getMessage());
            }

            $this->assertSame(1, DB::transactionLevel());
            $this->assertInstanceOf(PropertyBusinessDateOperationalLockContext::class, $context);
            $this->assertNotNull($nestedProof);

            $outerProof = $this->postgresCapabilityProof();
            $this->assertSame($nestedProof['backend_pid'], $outerProof['backend_pid']);
            $this->assertSame($nestedProof['transaction_id'], $outerProof['transaction_id']);
            $this->assertNotSame($nestedProof['capability_token'], $outerProof['capability_token']);

            $nightAuditQueries = [];
            try {
                app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
                $this->fail('Context retained after savepoint rollback must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame(NightAuditCheckoutConcurrencyGuardService::ERROR_INVALID_CONTEXT, $exception->getMessage());
            }

            $this->assertSame([], $nightAuditQueries);
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        $this->assertSame($before, $this->allCounts());
    }

    public function test_released_savepoint_preserves_capability_and_deterministic_fingerprint(): void
    {
        $before = $this->allCounts();

        DB::beginTransaction();
        try {
            $context = DB::transaction(fn () => app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence()));

            $this->assertSame(1, DB::transactionLevel());
            $proof = $this->postgresCapabilityProof();
            $this->assertSame($context->postgres_backend_pid, $proof['backend_pid']);
            $this->assertSame($context->postgres_transaction_id, $proof['transaction_id']);
            $this->assertNotSame('', $proof['capability_token']);

            $guard = app(NightAuditCheckoutConcurrencyGuardService::class);
            $first = $guard->attest($context);
            $second = $guard->attest($context);
            $this->assertSame($first->source_fingerprint, $second->source_fingerprint);
            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR, $second->status);
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        $this->assertSame($before, $this->allCounts());
    }

    public function test_second_acquisition_invalidates_first_context_before_night_audit_query(): void
    {
        $before = $this->allCounts();
        $nightAuditQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$nightAuditQueries): void {
            if (str_contains(strtolower($query->sql), 'night_audit_runs')) {
                $nightAuditQueries[] = $query->sql;
            }
        });

        DB::transaction(function () use (&$nightAuditQueries): void {
            $lockService = app(PropertyBusinessDateOperationalLockService::class);
            $guard = app(NightAuditCheckoutConcurrencyGuardService::class);
            $contextA = $lockService->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $attestationA = $guard->attest($contextA);

            $contextB = $lockService->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $attestationB = $guard->attest($contextB);
            $this->assertSame($attestationA->source_fingerprint, $attestationB->source_fingerprint);

            $nightAuditQueries = [];
            try {
                $guard->attest($contextA);
                $this->fail('A newer acquisition must invalidate the prior context.');
            } catch (DomainException $exception) {
                $this->assertSame(NightAuditCheckoutConcurrencyGuardService::ERROR_INVALID_CONTEXT, $exception->getMessage());
            }

            $this->assertSame([], $nightAuditQueries);
        });

        $this->assertSame($before, $this->allCounts());
    }

    public function test_transaction_proof_contract_is_non_nullable_and_fail_closed(): void
    {
        $contextReflection = new \ReflectionClass(PropertyBusinessDateOperationalLockContext::class);
        $backendType = $contextReflection->getProperty('postgres_backend_pid')->getType();
        $transactionType = $contextReflection->getProperty('postgres_transaction_id')->getType();

        $this->assertSame('int', (string) $backendType);
        $this->assertFalse($backendType?->allowsNull());
        $this->assertSame('string', (string) $transactionType);
        $this->assertFalse($transactionType?->allowsNull());

        $lockSource = file_get_contents(base_path('Modules/Foundation/Property/Services/PropertyBusinessDateOperationalLockService.php'));
        $guardSource = file_get_contents(base_path('Modules/Operations/NightAudit/Services/NightAuditCheckoutConcurrencyGuardService.php'));
        $this->assertStringContainsString('txid_current()::text AS transaction_id', $lockSource);
        $this->assertStringContainsString('private static ?\\WeakMap $issuedContexts', $lockSource);
        $this->assertStringContainsString("set_config('ivorq.na_a2_lock_capability', ?, true)", $lockSource);
        $this->assertStringContainsString("current_setting('ivorq.na_a2_lock_capability', true)", $lockSource);
        $this->assertStringContainsString('hash_equals(', $lockSource);
        $this->assertStringContainsString("'capability_token_hash' =>", $lockSource);
        $this->assertStringContainsString('assertIssuedForCurrentTransaction($context)', $guardSource);
        $this->assertStringNotContainsString('postgres_backend_pid === null', $guardSource);
        $this->assertStringNotContainsString('catch (Throwable) {', $lockSource);
        $this->assertStringNotContainsString('return null;', $lockSource);
        $this->assertStringContainsString('self::ERROR_INVALID_CONTEXT, 0, $exception', $lockSource);
    }

    public function test_guard_reports_active_attestation_with_deterministic_fingerprint_and_existing_evidence_validation(): void
    {
        app(NightAuditRunStartService::class)->start($this->actor);

        DB::transaction(function (): void {
            $context = app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $first = app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
            Carbon::setTestNow(Carbon::parse('2026-07-17 03:00:00', 'UTC'));
            $second = app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);

            $this->assertSame($this->attestationKeys(), array_keys($first->toArray()));
            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_ACTIVE, $first->status);
            $this->assertTrue($first->close_lock_active);
            $this->assertNotSame($first->evaluated_at, $second->evaluated_at);
            $this->assertSame($first->source_fingerprint, $second->source_fingerprint);
        });

        DB::table('night_audit_runs')->update([
            'status' => NightAuditRunStatusEnum::Aborted->value,
            'aborted_by' => $this->actor->id,
            'aborted_at' => now('UTC'),
            'abort_reason' => 'Retire valid active run for malformed evidence test.',
            'updated_by' => $this->actor->id,
            'updated_at' => now('UTC'),
        ]);
        NightAuditRun::withoutEvents(function (): void {
            DB::table('night_audit_runs')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'property_business_date_id' => $this->businessDate->id,
                'business_date_snapshot' => '2026-07-18',
                'property_timezone_snapshot' => 'Asia/Makassar',
                'attempt_number' => 2,
                'status' => NightAuditRunStatusEnum::InProgress,
                'started_by' => $this->actor->id,
                'started_at' => now('UTC'),
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        try {
            DB::transaction(function (): void {
                $context = app(PropertyBusinessDateOperationalLockService::class)
                    ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
                app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
            });
            $this->fail('Malformed active run evidence must fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame(NightAuditLockProjectionService::ERROR_INVALID_RUN, $exception->getMessage());
        }
    }

    public function test_guard_fails_closed_for_multiple_active_runs_and_performs_zero_writes(): void
    {
        $before = $this->allCounts();
        DB::transaction(function (): void {
            $context = app(PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);
        });
        $this->assertSame($before, $this->allCounts());

        $source = file_get_contents(base_path('Modules/Operations/NightAudit/Services/NightAuditCheckoutConcurrencyGuardService.php'));
        $this->assertStringContainsString(NightAuditCheckoutConcurrencyGuardService::ERROR_MULTIPLE_ACTIVE_RUNS, $source);
        $this->assertStringContainsString('if ($activeRuns->count() > 1)', $source);
    }

    public function test_start_and_abort_preserve_na_a1_behavior_through_shared_lock_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 01:00:00', 'UTC'));
        $first = app(NightAuditRunStartService::class)->start($this->actor);
        $second = app(NightAuditRunStartService::class)->start($this->actor);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $first->attempt_number);

        $aborted = app(NightAuditRunAbortService::class)->abort($this->actor, 'NA-A2 shared lock abort proof.');
        $this->assertSame($first->id, $aborted->id);
        $this->assertSame(NightAuditRunStatusEnum::Aborted, $aborted->status);

        Carbon::setTestNow(Carbon::parse('2026-07-17 02:00:00', 'UTC'));
        $restart = app(NightAuditRunStartService::class)->start($this->actor);
        $this->assertNotSame($first->id, $restart->id);
        $this->assertSame(2, $restart->attempt_number);
    }

    public function test_no_exposure_or_checkout_execution_surface_was_added(): void
    {
        foreach ([
            base_path('routes'),
            base_path('app/Http/Controllers'),
            base_path('Modules/Foundation/Property/Http'),
            base_path('Modules/Operations/NightAudit/Http'),
            base_path('Modules/Operations/NightAudit/Jobs'),
            base_path('Modules/Operations/NightAudit/Listeners'),
            base_path('resources/js'),
        ] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = $file->getRealPath();
                if (in_array($path, [
                    realpath(base_path('Modules/Foundation/Property/Services/PropertyBusinessDateOperationalLockService.php')),
                    realpath(base_path('Modules/Operations/NightAudit/Services/NightAuditCheckoutConcurrencyGuardService.php')),
                ], true)) {
                    continue;
                }
                $source = file_get_contents($path);
                $this->assertStringNotContainsString('NightAuditCheckoutConcurrencyGuardService', $source, $path);
                $this->assertStringNotContainsString('PropertyBusinessDateOperationalLockService', $source, $path);
                $this->assertStringNotContainsString('frontdesk.checkout-execution.execute', $source, $path);
                $this->assertStringNotContainsString('frontdesk-checkout-execution', $source, $path);
            }
        }

        $this->assertFalse(method_exists(NightAuditRunStartService::class, 'close'));
        $this->assertFalse(method_exists(NightAuditRunStartService::class, 'advance'));
        $this->assertFalse(method_exists(NightAuditRunStartService::class, 'executeCheckout'));
    }

    private function createAuthorizedContext(): void
    {
        app(CurrentPropertyService::class)->clear();

        $this->company = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
        $this->property = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $this->company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actor = \Database\Factories\UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);
        $this->actor->givePermissionTo([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
            NightAuditAuthorizationService::ABORT_PERMISSION,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session(['active_company_id' => $this->company->id, 'current_property_id' => $this->property->id]);
        auth()->login($this->actor);
        $this->actingAs($this->actor);

        $this->businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-17',
            'timezone_snapshot' => 'Asia/Makassar',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_by' => $this->actor->id,
            'opened_at' => Carbon::parse('2026-07-17 00:00:00', 'UTC'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function businessDateEvidence(): array
    {
        return app(NightAuditBusinessDateDependencyService::class)->project($this->actor);
    }

    private function insertRun(string $id, int $attempt): void
    {
        NightAuditRun::withoutEvents(function () use ($id, $attempt): void {
            DB::table('night_audit_runs')->insert([
                'id' => $id,
                'property_id' => $this->property->id,
                'property_business_date_id' => $this->businessDate->id,
                'business_date_snapshot' => '2026-07-17',
                'property_timezone_snapshot' => 'Asia/Makassar',
                'attempt_number' => $attempt,
                'status' => NightAuditRunStatusEnum::InProgress,
                'started_by' => $this->actor->id,
                'started_at' => now('UTC'),
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
    }

    /**
     * @return array{backend_pid: int, transaction_id: string, capability_token: string}
     */
    private function postgresCapabilityProof(): array
    {
        $row = DB::selectOne(
            "SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id, "
            . "current_setting('ivorq.na_a2_lock_capability', true) AS capability_token"
        );

        return [
            'backend_pid' => (int) $row->backend_pid,
            'transaction_id' => (string) $row->transaction_id,
            'capability_token' => (string) ($row->capability_token ?? ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function attestationKeys(): array
    {
        return [
            'attestation_version',
            'status',
            'owner',
            'transaction_bound',
            'close_lock_active',
            'property_id',
            'property_business_date_id',
            'business_date',
            'property_timezone',
            'source_fingerprint',
            'evaluated_at',
            'markers',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function allCounts(): array
    {
        $counts = [];
        foreach (['properties', 'property_business_dates', 'night_audit_runs'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}

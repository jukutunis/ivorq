<?php

namespace Tests\Postgres\Operations\NightAudit;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
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
use Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Modules\Operations\NightAudit\Services\NightAuditBusinessDateDependencyService;
use Modules\Operations\NightAudit\Services\NightAuditLockProjectionService;
use Modules\Operations\NightAudit\Services\NightAuditRunAbortService;
use Modules\Operations\NightAudit\Services\NightAuditRunStartService;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class NightAuditRunFoundationTest extends PostgresTestCase
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

    public function test_permissions_and_authorization_are_property_scoped_and_before_night_audit_or_business_date_queries(): void
    {
        foreach ([
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
            NightAuditAuthorizationService::ABORT_PERMISSION,
        ] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'web']);
        }

        $this->assertSame($this->property->id, app(NightAuditAuthorizationService::class)->authorizeView($this->actor)->id);
        $this->assertSame($this->property->id, app(NightAuditAuthorizationService::class)->authorizeStart($this->actor)->id);
        $this->assertSame($this->property->id, app(NightAuditAuthorizationService::class)->authorizeAbort($this->actor)->id);

        $cases = [
            'missing_company' => fn () => session()->forget('active_company_id'),
            'unknown_company' => fn () => session(['active_company_id' => (string) Str::ulid()]),
            'inactive_company' => fn () => $this->company->forceFill(['is_active' => false])->save(),
            'unknown_property' => fn () => app(CurrentPropertyService::class)->setPropertyId((string) Str::ulid()),
            'inactive_property' => fn () => $this->property->forceFill(['is_active' => false])->save(),
            'cross_company_property' => function (): void {
                $otherCompany = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
                $otherProperty = \Database\Factories\PropertyFactory::new()->create([
                    'company_id' => $otherCompany->id,
                    'timezone' => 'Asia/Makassar',
                    'currency' => 'USD',
                    'is_active' => true,
                ]);
                $this->actor->properties()->attach($otherProperty->id, [
                    'is_default' => false,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
                app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
            },
            'inactive_actor' => fn () => $this->actor->forceFill(['is_active' => false])->save(),
            'inactive_membership' => fn () => $this->actor->properties()->updateExistingPivot($this->property->id, ['status' => 'inactive']),
            'missing_night_audit_permission' => fn () => $this->actor->revokePermissionTo(NightAuditAuthorizationService::VIEW_PERMISSION),
            'missing_business_date_view' => fn () => $this->actor->revokePermissionTo(PropertyBusinessDateAuthorizationService::VIEW_PERMISSION),
        ];

        foreach ($cases as $name => $mutate) {
            $this->createAuthorizedContext();
            $mutate();

            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                app(NightAuditLockProjectionService::class)->project($this->actor);
                $this->fail("Authorization case {$name} must fail.");
            } catch (AuthorizationException $exception) {
                $this->assertSame(NightAuditAuthorizationService::FAILURE_MESSAGE, $exception->getMessage());
            }

            $this->assertNoForbiddenPreAuthorizationQueries($name);
            DB::disableQueryLog();
        }
    }

    public function test_bd_a1_dependency_validates_success_contract_and_normalizes_known_unavailable_evidence(): void
    {
        $projection = app(NightAuditBusinessDateDependencyService::class)->project($this->actor);

        $this->assertSame(NightAuditBusinessDateDependencyService::STATUS_OPEN, $projection['status']);
        $this->assertSame('PROPERTY_BUSINESS_DATE_SOURCE_PROVEN', $projection['source_classification']);
        $this->assertSame('Business Date / Night Audit', $projection['owner']);
        $this->assertTrue($projection['read_only']);
        $this->assertSame($this->businessDate->id, $projection['property_business_date_id']);
        $this->assertSame($this->property->id, $projection['property_id']);
        $this->assertSame('2026-07-17', $projection['business_date']);
        $this->assertSame('Open', $projection['lifecycle_status']);
        $this->assertSame('Asia/Makassar', $projection['property_timezone']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $projection['source_fingerprint']);

        $this->createAuthorizedContext(withBusinessDate: false);
        $unavailable = app(NightAuditBusinessDateDependencyService::class)->project($this->actor);
        $this->assertSame(NightAuditBusinessDateDependencyService::STATUS_UNAVAILABLE, $unavailable['status']);
        $this->assertSame('PROPERTY_BUSINESS_DATE_SOURCE_UNAVAILABLE', $unavailable['source_classification']);
        $this->assertSame([PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED], $unavailable['evidence_unavailable_codes']);
    }

    public function test_start_is_server_derived_idempotent_abort_releases_lock_and_restart_increments_attempt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 01:00:00', 'UTC'));
        $sourceCounts = $this->sourceCounts();

        $clearBefore = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertProjectionContract($clearBefore);
        $this->assertSame(NightAuditLockProjectionService::STATUS_CLEAR, $clearBefore['status']);
        $this->assertSame(NightAuditLockProjectionService::STATUS_CLEAR, $clearBefore['source_status']);
        $this->assertNull($clearBefore['run_status']);
        $this->assertFalse($clearBefore['close_lock_active']);

        $first = app(NightAuditRunStartService::class)->start($this->actor);
        $activityCount = Schema::hasTable('activity_log') ? DB::table('activity_log')->count() : 0;
        Carbon::setTestNow(Carbon::parse('2026-07-17 01:05:00', 'UTC'));
        $second = app(NightAuditRunStartService::class)->start($this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $first->attempt_number);
        $this->assertSame($this->actor->id, $first->started_by);
        $this->assertSame('2026-07-17T01:00:00.000000Z', $first->started_at->toJSON());
        $this->assertSame($this->property->id, $first->property_id);
        $this->assertSame($this->businessDate->id, $first->property_business_date_id);
        $this->assertSame('2026-07-17', $first->business_date_snapshot->format('Y-m-d'));
        $this->assertSame('Asia/Makassar', $first->property_timezone_snapshot);
        $this->assertSame(NightAuditRunStatusEnum::InProgress, $first->status);
        $this->assertSame(1, NightAuditRun::withoutGlobalScopes()->count());
        if (Schema::hasTable('activity_log')) {
            $this->assertSame($activityCount, DB::table('activity_log')->count());
        }

        $active = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertProjectionContract($active);
        $again = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertSame(NightAuditLockProjectionService::STATUS_ACTIVE, $active['status']);
        $this->assertSame(NightAuditLockProjectionService::STATUS_ACTIVE, $active['source_status']);
        $this->assertSame(NightAuditRunStatusEnum::InProgress->value, $active['run_status']);
        $this->assertTrue($active['close_lock_active']);
        $this->assertSame($first->id, $active['night_audit_run_id']);
        $this->assertSame($active['source_fingerprint'], $again['source_fingerprint']);

        Carbon::setTestNow(Carbon::parse('2026-07-17 01:10:00', 'UTC'));
        $aborted = app(NightAuditRunAbortService::class)->abort($this->actor, 'Operator requested controlled abort.');
        $this->assertSame($first->id, $aborted->id);
        $this->assertSame(NightAuditRunStatusEnum::Aborted, $aborted->status);
        $this->assertSame($this->actor->id, $aborted->aborted_by);
        $this->assertSame('2026-07-17T01:10:00.000000Z', $aborted->aborted_at->toJSON());
        $this->assertSame('Operator requested controlled abort.', $aborted->abort_reason);

        try {
            app(NightAuditRunAbortService::class)->abort($this->actor, 'Repeated abort should fail safely.');
            $this->fail('Repeated abort must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NightAuditRunAbortService::ERROR_ACTIVE_RUN_NOT_FOUND, $exception->getMessage());
        }

        $clearAfter = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertProjectionContract($clearAfter);
        $this->assertSame(NightAuditLockProjectionService::STATUS_CLEAR, $clearAfter['status']);
        $this->assertFalse($clearAfter['close_lock_active']);

        Carbon::setTestNow(Carbon::parse('2026-07-17 01:20:00', 'UTC'));
        $restart = app(NightAuditRunStartService::class)->start($this->actor);
        $this->assertNotSame($first->id, $restart->id);
        $this->assertSame(2, $restart->attempt_number);
        $this->assertSame($sourceCounts, $this->sourceCounts());
    }

    public function test_lock_projection_contract_matrix_and_unavailable_source_code_are_exact(): void
    {
        $clear = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertProjectionContract($clear);
        $this->assertSame(NightAuditLockProjectionService::PROJECTION_VERSION, $clear['projection_version']);
        $this->assertSame(NightAuditLockProjectionService::OWNER, $clear['owner']);
        $this->assertTrue($clear['read_only']);
        $this->assertSame(NightAuditLockProjectionService::STATUS_CLEAR, $clear['status']);
        $this->assertSame(NightAuditLockProjectionService::STATUS_CLEAR, $clear['source_status']);
        $this->assertSame(NightAuditLockProjectionService::SOURCE_PROVEN, $clear['source_classification']);
        $this->assertNull($clear['run_status']);
        $this->assertFalse($clear['close_lock_active']);

        $run = app(NightAuditRunStartService::class)->start($this->actor);
        $active = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertProjectionContract($active);
        $this->assertSame(NightAuditLockProjectionService::STATUS_ACTIVE, $active['status']);
        $this->assertSame(NightAuditLockProjectionService::STATUS_ACTIVE, $active['source_status']);
        $this->assertSame(NightAuditRunStatusEnum::InProgress->value, $active['run_status']);
        $this->assertSame($run->id, $active['night_audit_run_id']);
        $this->assertTrue($active['close_lock_active']);

        $this->createAuthorizedContext(withBusinessDate: false);
        $unavailable = app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertProjectionContract($unavailable);
        $this->assertSame(NightAuditLockProjectionService::STATUS_UNAVAILABLE, $unavailable['status']);
        $this->assertSame(PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED, $unavailable['source_status']);
        $this->assertSame([PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED], $unavailable['evidence_unavailable_codes']);
        $this->assertSame(NightAuditLockProjectionService::SOURCE_UNAVAILABLE, $unavailable['source_classification']);
        $this->assertNull($unavailable['close_lock_active']);
        $this->assertNull($unavailable['property_id']);
        $this->assertNull($unavailable['property_business_date_id']);
        $this->assertNull($unavailable['business_date']);
        $this->assertNull($unavailable['property_timezone']);
        $this->assertNull($unavailable['night_audit_run_id']);
    }

    public function test_persisted_run_contradictions_fail_closed_without_substituting_current_business_date(): void
    {
        $evidence = app(NightAuditBusinessDateDependencyService::class)->project($this->actor);

        $valid = $this->runEvidenceDouble();
        NightAuditLockProjectionService::assertRunEvidence($valid, $evidence);

        $otherProperty = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $this->company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $otherBusinessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $otherProperty->id,
            'business_date' => '2026-07-18',
            'timezone_snapshot' => 'Asia/Makassar',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_by' => $this->actor->id,
            'opened_at' => Carbon::parse('2026-07-17 00:00:00', 'UTC'),
        ]);

        foreach ([
            'wrong_property' => [$this->runEvidenceDouble(['property_id' => $otherProperty->id]), RuntimeException::class, NightAuditLockProjectionService::ERROR_CONTEXT_CONFLICT],
            'wrong_property_business_date' => [$this->runEvidenceDouble(['property_business_date_id' => $otherBusinessDate->id]), RuntimeException::class, NightAuditLockProjectionService::ERROR_CONTEXT_CONFLICT],
            'wrong_business_date_snapshot' => [$this->runEvidenceDouble(['business_date_snapshot' => '2026-07-18']), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'wrong_timezone_snapshot' => [$this->runEvidenceDouble(['property_timezone_snapshot' => 'UTC']), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'zero_attempt_number' => [$this->runEvidenceDouble(['attempt_number' => 0]), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'malformed_run_id' => [$this->runEvidenceDouble(['id' => 'not-a-ulid']), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'malformed_starter' => [$this->runEvidenceDouble(['started_by' => 'not-a-ulid']), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'missing_started_timestamp' => [$this->runEvidenceDouble(['started_at' => null]), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'malformed_created_actor' => [$this->runEvidenceDouble(['created_by' => 'not-a-ulid']), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'missing_created_timestamp' => [$this->runEvidenceDouble(['created_at' => null]), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'unexpected_abort_actor' => [$this->runEvidenceDouble(['aborted_by' => $this->actor->id]), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'unexpected_abort_timestamp' => [$this->runEvidenceDouble(['aborted_at' => Carbon::parse('2026-07-17 01:30:00', 'UTC')]), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
            'unexpected_abort_reason' => [$this->runEvidenceDouble(['abort_reason' => 'Unexpected abort evidence.']), DomainException::class, NightAuditLockProjectionService::ERROR_INVALID_RUN],
        ] as $case => [$run, $exceptionClass, $message]) {
            try {
                NightAuditLockProjectionService::assertRunEvidence($run, $evidence);
                $this->fail("{$case} must fail closed.");
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($exceptionClass, $exception, $case);
                $this->assertSame($message, $exception->getMessage(), $case);
            }
        }

        NightAuditRun::withoutEvents(function (): void {
            DB::table('night_audit_runs')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'property_business_date_id' => $this->businessDate->id,
                'business_date_snapshot' => '2026-07-18',
                'property_timezone_snapshot' => 'Asia/Makassar',
                'attempt_number' => 1,
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
            app(NightAuditRunStartService::class)->start($this->actor);
            $this->fail('Start must not return a corrupted existing active run.');
        } catch (DomainException $exception) {
            $this->assertSame(NightAuditLockProjectionService::ERROR_INVALID_RUN, $exception->getMessage());
        }
    }

    public function test_locked_context_revalidation_rejects_authorization_and_business_date_races_without_mutation(): void
    {
        $sourceCounts = $this->sourceCounts();
        $evidence = $this->businessDateEvidence();
        $this->app->instance(NightAuditAuthorizationService::class, new class($this->property) extends NightAuditAuthorizationService {
            private int $startCalls = 0;

            public function __construct(private Property $property) {}

            public function authorizeView(User $actor): Property
            {
                return $this->property;
            }

            public function authorizeStart(User $actor): Property
            {
                $this->startCalls++;
                if ($this->startCalls >= 3) {
                    throw new AuthorizationException(NightAuditAuthorizationService::FAILURE_MESSAGE);
                }

                if ($this->startCalls === 2) {
                    $this->property->forceFill(['is_active' => false])->save();
                }

                return $this->property;
            }

            public function authorizeAbort(User $actor): Property
            {
                return $this->property;
            }
        });
        $this->app->instance(NightAuditBusinessDateDependencyService::class, $this->businessDateDependencyDouble([
            $evidence,
            $evidence,
            $evidence,
        ]));

        try {
            app(NightAuditRunStartService::class)->start($this->actor);
            $this->fail('Inactive locked Property must reject start.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(AuthorizationException::class, $exception);
            $this->assertSame(NightAuditAuthorizationService::FAILURE_MESSAGE, $exception->getMessage());
        }
        $this->assertSame(0, NightAuditRun::withoutGlobalScopes()->count());
        $this->assertSame($sourceCounts, $this->sourceCounts());
        $this->forgetNightAuditServiceInstances();

        $this->createAuthorizedContext();
        $sourceCounts = $this->sourceCounts();
        $evidence = $this->businessDateEvidence();
        $changedEvidence = array_merge($evidence, [
            'business_date' => '2026-07-18',
            'source_fingerprint' => str_repeat('a', 64),
        ]);

        $this->app->instance(NightAuditBusinessDateDependencyService::class, $this->businessDateDependencyDouble([
            $evidence,
            $evidence,
            $changedEvidence,
        ]));

        try {
            app(NightAuditRunStartService::class)->start($this->actor);
            $this->fail('Changed locked Business Date evidence must reject start.');
        } catch (DomainException $exception) {
            $this->assertSame(NightAuditRunStartService::ERROR_INVALID_BUSINESS_DATE, $exception->getMessage());
        }
        $this->assertSame(0, NightAuditRun::withoutGlobalScopes()->count());
        $this->assertSame($sourceCounts, $this->sourceCounts());
        $this->forgetNightAuditServiceInstances();

        $this->createAuthorizedContext();
        $run = app(NightAuditRunStartService::class)->start($this->actor);
        $evidence = $this->businessDateEvidence();
        $changedEvidence = array_merge($evidence, [
            'property_timezone' => 'UTC',
            'source_fingerprint' => str_repeat('b', 64),
        ]);
        $this->app->instance(NightAuditBusinessDateDependencyService::class, $this->businessDateDependencyDouble([
            $evidence,
            $evidence,
            $changedEvidence,
        ]));

        try {
            app(NightAuditRunAbortService::class)->abort($this->actor, 'Abort must reject stale Business Date.');
            $this->fail('Changed locked Business Date evidence must reject abort.');
        } catch (DomainException $exception) {
            $this->assertSame(NightAuditRunAbortService::ERROR_INVALID_BUSINESS_DATE, $exception->getMessage());
        }
        $this->assertSame(NightAuditRunStatusEnum::InProgress, $run->fresh()->status);
        $this->forgetNightAuditServiceInstances();
    }

    public function test_active_fingerprint_uses_persisted_evidence_and_ignores_evaluated_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 01:00:00', 'UTC'));
        app(NightAuditRunStartService::class)->start($this->actor);

        $first = app(NightAuditLockProjectionService::class)->project($this->actor);
        Carbon::setTestNow(Carbon::parse('2026-07-17 01:30:00', 'UTC'));
        $second = app(NightAuditLockProjectionService::class)->project($this->actor);

        $this->assertNotSame($first['evaluated_at'], $second['evaluated_at']);
        $this->assertSame($first['source_fingerprint'], $second['source_fingerprint']);

        DB::table('night_audit_runs')->where('id', $first['night_audit_run_id'])->update([
            'status' => NightAuditRunStatusEnum::Aborted->value,
            'aborted_by' => $this->actor->id,
            'aborted_at' => now('UTC'),
            'abort_reason' => 'Abort so a contradictory fixture can be inserted.',
            'updated_by' => $this->actor->id,
            'updated_at' => now('UTC'),
        ]);

        NightAuditRun::withoutEvents(function (): void {
            DB::table('night_audit_runs')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'property_business_date_id' => $this->businessDate->id,
                'business_date_snapshot' => '2026-07-17',
                'property_timezone_snapshot' => 'UTC',
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
            app(NightAuditLockProjectionService::class)->project($this->actor);
            $this->fail('Contradictory persisted snapshot must fail instead of using current BD-A1 values.');
        } catch (DomainException $exception) {
            $this->assertSame(NightAuditLockProjectionService::ERROR_INVALID_RUN, $exception->getMessage());
        }
    }

    public function test_abort_reason_validation_and_immutable_evidence_guards(): void
    {
        foreach (['short', str_repeat('x', 501), "Invalid\0reason"] as $reason) {
            try {
                app(NightAuditRunAbortService::class)->abort($this->actor, $reason);
                $this->fail('Invalid abort reason must fail.');
            } catch (DomainException $exception) {
                $this->assertSame(NightAuditRunAbortService::ERROR_INVALID_REASON, $exception->getMessage());
            }
        }

        $run = app(NightAuditRunStartService::class)->start($this->actor);
        app(NightAuditRunAbortService::class)->abort($this->actor, 'Valid abort evidence.');

        $this->assertFalse(method_exists(NightAuditRunStartService::class, 'close'));
        $this->assertFalse(method_exists(NightAuditRunStartService::class, 'advance'));
        $this->assertFalse(method_exists(NightAuditRunStartService::class, 'reopen'));

        try {
            $run->fresh()->delete();
            $this->fail('Model delete must be rejected.');
        } catch (\Shared\Exceptions\BusinessLogicException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        try {
            DB::table('night_audit_runs')->where('id', $run->id)->update(['attempt_number' => 99]);
            $this->fail('Raw immutable update must fail.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('NA_A1_NIGHT_AUDIT_RUN_FOUNDATION_IMMUTABLE', $exception->getMessage());
        }
    }

    public function test_projection_zero_write_static_boundaries_and_legacy_close_isolation(): void
    {
        $before = $this->allCounts();
        app(NightAuditLockProjectionService::class)->project($this->actor);
        $this->assertSame($before, $this->allCounts());

        foreach (glob(base_path('Modules/Operations/NightAudit/**/*.php')) ?: [] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('BusinessDateCloseService', $source);
            $this->assertStringNotContainsString('BusinessDateCloseExecutionService', $source);
            $this->assertStringNotContainsString('PropertyBusinessDateInitializationService', $source);
            $this->assertStringNotContainsString('FrontDesk', $source);
            $this->assertStringNotContainsString('GeneralCashier', $source);
            $this->assertStringNotContainsString('Inventory', $source);
            $this->assertStringNotContainsString('dispatch(', $source);
            $this->assertStringNotContainsString('ShouldQueue', $source);
        }

        $this->assertCount(0, glob(base_path('Modules/Operations/NightAudit/Http/Controllers/*.php')) ?: []);
        foreach (glob(base_path('routes/*.php')) ?: [] as $routeFile) {
            $this->assertStringNotContainsString('NightAuditRunStartService', file_get_contents($routeFile));
            $this->assertStringNotContainsString('NightAuditRunAbortService', file_get_contents($routeFile));
        }
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function assertProjectionContract(array $projection): void
    {
        $this->assertSame($this->projectionKeys(), array_keys($projection));
        $this->assertSame(NightAuditLockProjectionService::PROJECTION_VERSION, $projection['projection_version']);
        $this->assertSame(NightAuditLockProjectionService::OWNER, $projection['owner']);
        $this->assertTrue($projection['read_only']);
    }

    /**
     * @return array<int, string>
     */
    private function projectionKeys(): array
    {
        return [
            'projection_version',
            'status',
            'source_status',
            'source_classification',
            'owner',
            'read_only',
            'close_lock_active',
            'property_id',
            'property_business_date_id',
            'business_date',
            'property_timezone',
            'night_audit_run_id',
            'attempt_number',
            'run_status',
            'started_at',
            'started_by',
            'evidence_unavailable_codes',
            'source_fingerprint',
            'evaluated_at',
            'markers',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function runEvidenceDouble(array $overrides = []): NightAuditRun
    {
        $attributes = array_merge([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'property_business_date_id' => $this->businessDate->id,
            'business_date_snapshot' => '2026-07-17',
            'property_timezone_snapshot' => 'Asia/Makassar',
            'attempt_number' => 1,
            'status' => NightAuditRunStatusEnum::InProgress->value,
            'started_by' => $this->actor->id,
            'started_at' => Carbon::parse('2026-07-17 01:00:00', 'UTC'),
            'aborted_by' => null,
            'aborted_at' => null,
            'abort_reason' => null,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => Carbon::parse('2026-07-17 01:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-07-17 01:00:00', 'UTC'),
        ], $overrides);

        $run = new NightAuditRun();
        $run->setRawAttributes($attributes, true);
        $run->exists = true;

        return $run;
    }

    /**
     * @param array<int, array<string, mixed>|callable> $responses
     */
    private function businessDateDependencyDouble(array $responses): NightAuditBusinessDateDependencyService
    {
        return new class($responses) extends NightAuditBusinessDateDependencyService {
            /** @param array<int, array<string, mixed>|callable> $responses */
            public function __construct(private array $responses) {}

            /** @return array<string, mixed> */
            public function project(User $actor): array
            {
                $response = array_shift($this->responses);
                if (is_callable($response)) {
                    return $response();
                }

                return $response ?? [];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function businessDateEvidence(): array
    {
        return [
            'status' => NightAuditBusinessDateDependencyService::STATUS_OPEN,
            'source_status' => NightAuditBusinessDateDependencyService::STATUS_OPEN,
            'source_classification' => NightAuditBusinessDateDependencyService::SOURCE_CLASSIFICATION_PROVEN,
            'owner' => NightAuditLockProjectionService::OWNER,
            'read_only' => true,
            'property_business_date_id' => $this->businessDate->id,
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate->business_date->format('Y-m-d'),
            'lifecycle_status' => 'Open',
            'property_timezone' => (string) $this->businessDate->timezone_snapshot,
            'opened_at' => $this->businessDate->opened_at->utc()->toISOString(),
            'opened_by' => $this->actor->id,
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => hash('sha256', implode('|', [
                $this->property->id,
                $this->businessDate->id,
                $this->businessDate->business_date->format('Y-m-d'),
                (string) $this->businessDate->timezone_snapshot,
                $this->businessDate->opened_at->utc()->toISOString(),
                $this->actor->id,
            ])),
            'evaluated_at' => now('UTC')->toISOString(),
        ];
    }

    private function forgetNightAuditServiceInstances(): void
    {
        foreach ([
            NightAuditAuthorizationService::class,
            NightAuditBusinessDateDependencyService::class,
            NightAuditRunStartService::class,
            NightAuditRunAbortService::class,
            NightAuditLockProjectionService::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function createAuthorizedContext(bool $withBusinessDate = true): void
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
        session()->forget(['active_property_id', 'current_property_id']);
        session(['active_company_id' => $this->company->id]);
        auth()->login($this->actor);
        $this->actingAs($this->actor);

        if ($withBusinessDate) {
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
    }

    private function assertNoForbiddenPreAuthorizationQueries(string $case): void
    {
        $sql = implode("\n", array_map(fn (array $query): string => $query['query'], DB::getQueryLog()));
        foreach ([
            'property_business_dates',
            'night_audit_runs',
            'front_desk',
            'folios',
            'guest_payment',
            'general_cashier',
            'cashier',
            'journal_entries',
            'inventory',
            'housekeeping',
            'engineering',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $sql, "{$case} queried {$needle} before authorization.");
        }
    }

    private function sourceCounts(): array
    {
        return $this->counts([
            'property_business_dates',
            'properties',
            'companies',
            'users',
            'property_user',
            'front_desk_stays',
            'folios',
            'folio_items',
            'guest_payment_transactions',
            'cashier_sessions',
            'journal_entries',
            'inventory_transactions',
        ]);
    }

    private function allCounts(): array
    {
        return $this->counts(array_merge(array_keys($this->sourceCounts()), ['night_audit_runs']));
    }

    /**
     * @param array<int|string, string|int> $tables
     * @return array<string, int>
     */
    private function counts(array $tables): array
    {
        $tables = array_values(array_unique(array_filter($tables, 'is_string')));
        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}

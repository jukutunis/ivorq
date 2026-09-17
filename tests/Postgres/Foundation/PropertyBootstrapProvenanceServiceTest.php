<?php

namespace Tests\Postgres\Foundation;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningStatusEnum;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\Property\Services\PropertyBootstrapProvenanceService;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Tests\PostgresTestCase;

class PropertyBootstrapProvenanceServiceTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const REQUEST_FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const EVIDENCE_FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CANONICAL_SHA = 'cd000728d52f45d71c6ff4fbba76ef64c7dbb973';

    private const SOURCE = 'owner-controlled-bootstrap';

    private PropertyBootstrapProvenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PropertyBootstrapProvenanceService::class);
    }

    public function test_first_start_creates_one_in_progress_run_without_domain_provisioning(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $trackedTables = [
            'users',
            'companies',
            'properties',
            'property_user',
            'property_business_dates',
            'gl_financial_periods',
            'inventory_categories',
            'inventory_units',
            'inventory_locations',
            'inventory_items',
            'cost_authority_enrollment_groups',
            'cost_delivery_pilot_properties',
            'cost_delivery_cutovers',
        ];
        $before = $this->tableCounts($trackedTables);

        $run = $this->service->start(
            $actor,
            PropertyBootstrapProvisioningEnvironmentEnum::Operational,
            'bootstrap-run-001',
            self::REQUEST_FINGERPRINT,
            self::CANONICAL_SHA,
            self::SOURCE,
        );

        $this->assertSame(1, PropertyBootstrapProvisioningRun::query()->count());
        $this->assertSame(PropertyBootstrapProvisioningEnvironmentEnum::Operational, $run->environment);
        $this->assertSame(PropertyBootstrapProvisioningStatusEnum::InProgress, $run->status);
        $this->assertSame($actor->id, $run->initiated_by);
        $this->assertSame([], $run->evidence);
        $this->assertNull($run->company_id);
        $this->assertNull($run->property_id);
        $this->assertNull($run->completed_at);
        $this->assertNull($run->failed_at);
        $this->assertSame($before, $this->tableCounts($trackedTables));
    }

    public function test_start_requires_the_same_authenticated_active_actor(): void
    {
        $actor = $this->actor();

        try {
            $this->start($actor, 'unauthenticated');
            $this->fail('An unauthenticated start must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(PropertyBootstrapProvenanceService::AUTHORIZATION_FAILURE, $exception->getMessage());
        }

        $inactive = $this->actor(['is_active' => false]);
        $this->actingAs($inactive);
        $this->expectException(AuthorizationException::class);
        $this->start($inactive, 'inactive');
    }

    public function test_supplied_actor_mismatch_is_rejected(): void
    {
        $supplied = $this->actor();
        $authenticated = $this->actor();
        $this->actingAs($authenticated);

        $this->expectException(AuthorizationException::class);
        $this->start($supplied, 'actor-mismatch');
    }

    public function test_operational_and_rehearsal_are_distinct_idempotency_namespaces(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);

        $operational = $this->start($actor, 'same-key', PropertyBootstrapProvisioningEnvironmentEnum::Operational);
        $rehearsal = $this->start($actor, 'same-key', PropertyBootstrapProvisioningEnvironmentEnum::Rehearsal);

        $this->assertNotSame($operational->id, $rehearsal->id);
        $this->assertSame(2, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_exact_in_progress_and_completed_retries_are_mutation_free(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'exact-retry');
        $initialUpdatedAt = $run->updated_at;

        $inProgressRetry = $this->start($actor, 'exact-retry');

        $this->assertSame($run->id, $inProgressRetry->id);
        $this->assertTrue($initialUpdatedAt->equalTo($inProgressRetry->updated_at));

        [$company, $property] = $this->companyAndProperty();
        $run = $this->service->bindCompany($run, $actor, $company->id);
        $run = $this->service->bindProperty($run, $actor, $property->id);
        $run = $this->service->complete($run, $actor, ['domains' => ['foundation']], self::EVIDENCE_FINGERPRINT);
        $completedUpdatedAt = $run->updated_at;

        $completedRetry = $this->start($actor, 'exact-retry');

        $this->assertSame($run->id, $completedRetry->id);
        $this->assertSame(PropertyBootstrapProvisioningStatusEnum::Completed, $completedRetry->status);
        $this->assertTrue($completedUpdatedAt->equalTo($completedRetry->updated_at));
    }

    public function test_changed_request_identity_fails_closed(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $this->start($actor, 'identity-conflict');

        $cases = [
            ['cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', self::CANONICAL_SHA, self::SOURCE],
            [self::REQUEST_FINGERPRINT, '1111111111111111111111111111111111111111', self::SOURCE],
            [self::REQUEST_FINGERPRINT, self::CANONICAL_SHA, 'different-source'],
        ];

        foreach ($cases as [$fingerprint, $sha, $source]) {
            try {
                $this->service->start(
                    $actor,
                    PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                    'identity-conflict',
                    $fingerprint,
                    $sha,
                    $source,
                );
                $this->fail('Changed request identity must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame(PropertyBootstrapProvenanceService::ERROR_IDEMPOTENCY_CONFLICT, $exception->getMessage());
            }
        }

        $this->assertSame(1, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_failed_run_is_terminal_and_cannot_be_retried(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'failed-retry');
        $failed = $this->service->fail($run, $actor, 'BOOTSTRAP_ABORTED', ['phase' => 'foundation']);

        $this->assertSame(PropertyBootstrapProvisioningStatusEnum::Failed, $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->completed_at);
        $this->assertSame('BOOTSTRAP_ABORTED', $failed->failure_code);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(PropertyBootstrapProvenanceService::ERROR_FAILED_RUN_TERMINAL);
        $this->start($actor, 'failed-retry');
    }

    public function test_company_binding_is_single_assignment_and_exact_repeat_is_mutation_free(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'company-binding');
        $company = CompanyFactory::new()->create();
        $otherCompany = CompanyFactory::new()->create();

        $bound = $this->service->bindCompany($run, $actor, $company->id);
        $boundUpdatedAt = $bound->updated_at;
        $repeated = $this->service->bindCompany($bound, $actor, $company->id);

        $this->assertSame($company->id, $repeated->company_id);
        $this->assertTrue($boundUpdatedAt->equalTo($repeated->updated_at));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(PropertyBootstrapProvenanceService::ERROR_COMPANY_REBIND);
        $this->service->bindCompany($repeated, $actor, $otherCompany->id);
    }

    public function test_property_binding_is_single_assignment_and_exact_repeat_is_mutation_free(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'property-binding');
        [$company, $property] = $this->companyAndProperty();
        $otherProperty = PropertyFactory::new()->create(['company_id' => $company->id]);
        $run = $this->service->bindCompany($run, $actor, $company->id);

        $bound = $this->service->bindProperty($run, $actor, $property->id);
        $boundUpdatedAt = $bound->updated_at;
        $repeated = $this->service->bindProperty($bound, $actor, $property->id);

        $this->assertSame($property->id, $repeated->property_id);
        $this->assertTrue($boundUpdatedAt->equalTo($repeated->updated_at));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(PropertyBootstrapProvenanceService::ERROR_PROPERTY_REBIND);
        $this->service->bindProperty($repeated, $actor, $otherProperty->id);
    }

    public function test_property_and_company_mismatch_fails_closed_in_either_binding_order(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        [$companyA, $propertyA] = $this->companyAndProperty();
        $companyB = CompanyFactory::new()->create();

        $companyFirst = $this->start($actor, 'mismatch-company-first');
        $companyFirst = $this->service->bindCompany($companyFirst, $actor, $companyB->id);
        try {
            $this->service->bindProperty($companyFirst, $actor, $propertyA->id);
            $this->fail('A Property from another Company must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(PropertyBootstrapProvenanceService::ERROR_PROPERTY_COMPANY_MISMATCH, $exception->getMessage());
        }

        $propertyFirst = $this->start($actor, 'mismatch-property-first');
        $propertyFirst = $this->service->bindProperty($propertyFirst, $actor, $propertyA->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(PropertyBootstrapProvenanceService::ERROR_PROPERTY_COMPANY_MISMATCH);
        $this->service->bindCompany($propertyFirst, $actor, $companyB->id);
    }

    public function test_partial_unique_index_prevents_parallel_active_or_completed_property_universes(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        [$company, $property] = $this->companyAndProperty();
        $first = $this->start($actor, 'property-universe-a');
        $second = $this->start($actor, 'property-universe-b');
        $first = $this->service->bindCompany($first, $actor, $company->id);
        $this->service->bindProperty($first, $actor, $property->id);
        $second = $this->service->bindCompany($second, $actor, $company->id);

        $this->expectException(QueryException::class);
        $this->service->bindProperty($second, $actor, $property->id);
    }

    public function test_evidence_is_replaceable_only_while_in_progress_and_must_be_safe_json(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'evidence');

        $run = $this->service->recordEvidence($run, $actor, ['phase' => 'company']);
        $run = $this->service->recordEvidence($run, $actor, ['phase' => 'property', 'rows' => ['property' => '01']]);
        $this->assertEquals(['phase' => 'property', 'rows' => ['property' => '01']], $run->evidence);

        foreach ([['password' => 'forbidden'], ['not_json' => INF]] as $unsafe) {
            try {
                $this->service->recordEvidence($run, $actor, $unsafe);
                $this->fail('Unsafe or non-JSON evidence must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $failed = $this->service->fail($run, $actor, 'EVIDENCE_STOPPED');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(PropertyBootstrapProvenanceService::ERROR_RUN_NOT_IN_PROGRESS);
        $this->service->recordEvidence($failed, $actor, ['phase' => 'too-late']);
    }

    public function test_only_initiating_actor_may_mutate_an_in_progress_run(): void
    {
        $initiator = $this->actor();
        $otherActor = $this->actor();
        $this->actingAs($initiator);
        $run = $this->start($initiator, 'actor-mutation');
        $this->actingAs($otherActor);

        $this->expectException(AuthorizationException::class);
        $this->service->recordEvidence($run, $otherActor, ['phase' => 'unauthorized']);
    }

    public function test_completion_requires_company_and_property_and_records_terminal_evidence(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'complete');

        try {
            $this->service->complete($run, $actor, ['complete' => true], self::EVIDENCE_FINGERPRINT);
            $this->fail('Completion without Company and Property must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(PropertyBootstrapProvenanceService::ERROR_COMPLETION_BINDINGS_REQUIRED, $exception->getMessage());
        }

        [$company, $property] = $this->companyAndProperty();
        $run = $this->service->bindCompany($run, $actor, $company->id);
        $run = $this->service->bindProperty($run, $actor, $property->id);
        $completed = $this->service->complete(
            $run,
            $actor,
            ['domains' => ['foundation' => 'complete']],
            self::EVIDENCE_FINGERPRINT,
        );

        $this->assertSame(PropertyBootstrapProvisioningStatusEnum::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNull($completed->failed_at);
        $this->assertNull($completed->failure_code);
        $this->assertSame(self::EVIDENCE_FINGERPRINT, $completed->evidence_fingerprint);
        $this->assertSame(['domains' => ['foundation' => 'complete']], $completed->evidence);

        $this->expectException(RuntimeException::class);
        $this->service->fail($completed, $actor, 'TOO_LATE');
    }

    public function test_failure_code_is_bounded_and_failure_evidence_rejects_raw_diagnostics(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'failure-validation');

        foreach (['', str_repeat('A', 101), 'contains spaces'] as $invalidCode) {
            try {
                $this->service->fail($run, $actor, $invalidCode);
                $this->fail('Invalid failure code must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        foreach ([['stack_trace' => 'trace'], ['sql' => 'select secret']] as $unsafeEvidence) {
            try {
                $this->service->fail($run, $actor, 'SAFE_CODE', $unsafeEvidence);
                $this->fail('Raw failure diagnostics must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(PropertyBootstrapProvisioningStatusEnum::InProgress, $run->fresh()->status);
    }

    public function test_database_rejects_updates_and_deletes_of_completed_and_failed_rows(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        [$company, $property] = $this->companyAndProperty();

        $completed = $this->start($actor, 'terminal-completed');
        $completed = $this->service->bindCompany($completed, $actor, $company->id);
        $completed = $this->service->bindProperty($completed, $actor, $property->id);
        $completed = $this->service->complete($completed, $actor, [], self::EVIDENCE_FINGERPRINT);

        $failed = $this->start($actor, 'terminal-failed', PropertyBootstrapProvisioningEnvironmentEnum::Rehearsal);
        $failed = $this->service->fail($failed, $actor, 'CONTROLLED_FAILURE');

        foreach ([$completed, $failed] as $terminal) {
            $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')
                ->where('id', $terminal->id)
                ->update(['evidence' => json_encode(['changed' => true], JSON_THROW_ON_ERROR)]));
            $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')
                ->where('id', $terminal->id)
                ->delete());
        }

        $this->assertSame(2, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_database_rejects_identity_and_bound_reference_rewrites(): void
    {
        $actor = $this->actor();
        $otherActor = $this->actor();
        $this->actingAs($actor);
        $run = $this->start($actor, 'immutable-fields');

        $identityChanges = [
            'id' => (string) Str::ulid(),
            'environment' => PropertyBootstrapProvisioningEnvironmentEnum::Rehearsal->value,
            'idempotency_key' => 'rewritten-key',
            'request_fingerprint' => str_repeat('c', 64),
            'canonical_sha' => str_repeat('1', 40),
            'source' => 'rewritten-source',
            'initiated_by' => $otherActor->id,
            'started_at' => now()->addDay(),
            'created_at' => now()->addDay(),
        ];

        foreach ($identityChanges as $column => $value) {
            $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')
                ->where('id', $run->id)
                ->update([$column => $value]));
        }

        [$company, $property] = $this->companyAndProperty();
        [$otherCompany, $otherProperty] = $this->companyAndProperty();
        $run = $this->service->bindCompany($run, $actor, $company->id);
        $run = $this->service->bindProperty($run, $actor, $property->id);

        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')
            ->where('id', $run->id)
            ->update(['company_id' => $otherCompany->id]));
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')
            ->where('id', $run->id)
            ->update(['property_id' => $otherProperty->id]));
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')
            ->where('id', $run->id)
            ->delete());
    }

    public function test_database_enforces_status_timestamp_and_fingerprint_consistency(): void
    {
        $actor = $this->actor();

        $invalidRows = [
            array_merge($this->rawRun($actor, 'bad-in-progress'), ['completed_at' => now()]),
            array_merge($this->rawRun($actor, 'direct-completed-insert'), [
                'status' => PropertyBootstrapProvisioningStatusEnum::Completed->value,
                'completed_at' => now(),
                'evidence_fingerprint' => self::EVIDENCE_FINGERPRINT,
            ]),
            array_merge($this->rawRun($actor, 'bad-completed'), [
                'status' => PropertyBootstrapProvisioningStatusEnum::Completed->value,
                'completed_at' => now(),
                'evidence_fingerprint' => null,
            ]),
            array_merge($this->rawRun($actor, 'bad-failed'), [
                'status' => PropertyBootstrapProvisioningStatusEnum::Failed->value,
                'failed_at' => now(),
                'failure_code' => null,
            ]),
            array_merge($this->rawRun($actor, 'bad-request-fingerprint'), ['request_fingerprint' => 'not-sha256']),
            array_merge($this->rawRun($actor, 'bad-canonical-sha'), ['canonical_sha' => 'not-git-sha']),
        ];

        foreach ($invalidRows as $row) {
            $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->insert($row));
        }

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_service_rejects_malformed_fingerprints_and_canonical_sha(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);

        $cases = [
            ['short', self::CANONICAL_SHA],
            [str_repeat('z', 64), self::CANONICAL_SHA],
            [self::REQUEST_FINGERPRINT, 'short'],
            [self::REQUEST_FINGERPRINT, str_repeat('z', 40)],
        ];

        foreach ($cases as $index => [$fingerprint, $sha]) {
            try {
                $this->service->start(
                    $actor,
                    PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                    "malformed-{$index}",
                    $fingerprint,
                    $sha,
                    self::SOURCE,
                );
                $this->fail('Malformed fingerprints and SHAs must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $run = $this->start($actor, 'malformed-completion');
        [$company, $property] = $this->companyAndProperty();
        $run = $this->service->bindCompany($run, $actor, $company->id);
        $run = $this->service->bindProperty($run, $actor, $property->id);

        $this->expectException(InvalidArgumentException::class);
        $this->service->complete($run, $actor, [], 'bad-evidence-fingerprint');
    }

    public function test_concurrent_same_key_starts_converge_to_one_run(): void
    {
        $actor = $this->actor();
        $results = $this->spawnConcurrentStartWorkers($actor);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame(1, $result['row_count']);
            $this->assertSame(PropertyBootstrapProvisioningStatusEnum::InProgress->value, $result['status']);
        }

        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
        $this->assertSame($results[0]['run_id'], $results[1]['run_id']);
        $this->assertSame(1, PropertyBootstrapProvisioningRun::query()->count());
    }

    private function actor(array $attributes = []): User
    {
        return UserFactory::new()->create(array_merge(['is_active' => true], $attributes));
    }

    private function start(
        User $actor,
        string $idempotencyKey,
        PropertyBootstrapProvisioningEnvironmentEnum $environment = PropertyBootstrapProvisioningEnvironmentEnum::Operational,
    ): PropertyBootstrapProvisioningRun {
        return $this->service->start(
            $actor,
            $environment,
            $idempotencyKey,
            self::REQUEST_FINGERPRINT,
            self::CANONICAL_SHA,
            self::SOURCE,
        );
    }

    private function companyAndProperty(): array
    {
        $company = CompanyFactory::new()->create();
        $property = PropertyFactory::new()->create(['company_id' => $company->id]);

        return [$company, $property];
    }

    private function tableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The database mutation must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function rawRun(User $actor, string $idempotencyKey): array
    {
        return [
            'id' => (string) Str::ulid(),
            'environment' => PropertyBootstrapProvisioningEnvironmentEnum::Operational->value,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => self::REQUEST_FINGERPRINT,
            'status' => PropertyBootstrapProvisioningStatusEnum::InProgress->value,
            'canonical_sha' => self::CANONICAL_SHA,
            'source' => self::SOURCE,
            'initiated_by' => $actor->id,
            'company_id' => null,
            'property_id' => null,
            'started_at' => now(),
            'completed_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'evidence' => json_encode([], JSON_THROW_ON_ERROR),
            'evidence_fingerprint' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function spawnConcurrentStartWorkers(User $actor): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b4c-concurrency-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);

        $workerFile = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $barrier = $directory.DIRECTORY_SEPARATOR.'start';
        file_put_contents($workerFile, $this->concurrencyWorkerSource());

        $processes = [];
        $created = [$workerFile];

        try {
            foreach (['a', 'b'] as $workerId) {
                $argumentsFile = $directory.DIRECTORY_SEPARATOR."arguments-{$workerId}.json";
                $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$workerId}.json";
                $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$workerId}.txt";
                array_push($created, $argumentsFile, $resultFile, $stderrFile, "{$barrier}-ready-{$workerId}");

                file_put_contents($argumentsFile, json_encode([
                    'worker_id' => $workerId,
                    'actor_id' => $actor->id,
                    'barrier' => $barrier,
                    'result_file' => $resultFile,
                    'environment' => PropertyBootstrapProvisioningEnvironmentEnum::Operational->value,
                    'idempotency_key' => 'concurrent-start',
                    'request_fingerprint' => self::REQUEST_FINGERPRINT,
                    'canonical_sha' => self::CANONICAL_SHA,
                    'source' => self::SOURCE,
                ], JSON_THROW_ON_ERROR));

                $specification = [
                    ['pipe', 'r'],
                    ['file', $stderrFile, 'a'],
                    ['file', $stderrFile, 'a'],
                ];
                $process = proc_open(
                    [PHP_BINARY, $workerFile, base_path(), $argumentsFile],
                    $specification,
                    $pipes,
                    base_path(),
                    array_merge(getenv(), [
                        'APP_ENV' => 'testing',
                        'DB_CONNECTION' => 'pgsql',
                        'DB_DATABASE' => 'ivorq_testing',
                    ]),
                );
                if (! is_resource($process)) {
                    $this->fail('Unable to spawn the B4C concurrency worker.');
                }
                fclose($pipes[0]);

                $processes[] = [
                    'process' => $process,
                    'result_file' => $resultFile,
                    'stderr_file' => $stderrFile,
                ];
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['process'], 15);
                $decoded = is_file($process['result_file'])
                    ? json_decode((string) file_get_contents($process['result_file']), true)
                    : ['error' => 'missing result file'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result file'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr_file'])
                    ? trim((string) file_get_contents($process['stderr_file']))
                    : '';
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach ($created as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }

    private function waitForProcess($process, int $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                proc_close($process);

                return $exitCode;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        return 124;
    }

    private function concurrencyWorkerSource(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\Property\Services\PropertyBootstrapProvenanceService;
use Modules\Foundation\User\Models\User;

$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $actor = User::query()->findOrFail($arguments['actor_id']);
    Auth::login($actor);
    file_put_contents($arguments['barrier'].'-ready-'.$arguments['worker_id'], 'ready');

    $deadline = microtime(true) + 10;
    while (count(glob($arguments['barrier'].'-ready-*') ?: []) < 2 && microtime(true) < $deadline) {
        usleep(25000);
    }

    $run = app(PropertyBootstrapProvenanceService::class)->start(
        $actor,
        PropertyBootstrapProvisioningEnvironmentEnum::from($arguments['environment']),
        $arguments['idempotency_key'],
        $arguments['request_fingerprint'],
        $arguments['canonical_sha'],
        $arguments['source'],
    );

    file_put_contents($arguments['result_file'], json_encode([
        'run_id' => $run->id,
        'status' => $run->status->value,
        'row_count' => PropertyBootstrapProvisioningRun::query()->count(),
        'php_pid' => getmypid(),
        'pg_backend_pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $throwable) {
    file_put_contents($arguments['result_file'], json_encode([
        'error' => $throwable::class.': '.$throwable->getMessage(),
        'php_pid' => getmypid(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    }
}

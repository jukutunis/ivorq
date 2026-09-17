<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodMissingException;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Services\FinancialPeriodAuthorizationService;
use Modules\Finance\GeneralLedger\Services\FinancialPeriodInitializationService;
use Modules\Finance\GeneralLedger\Services\PostingPeriodGuard;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\CurrentBusinessDateService;
use Modules\Foundation\User\Models\User;
use ReflectionMethod;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class FinancialPeriodInitializationServiceTest extends PostgresTestCase
{
    use DatabaseMigrations, FinancialPeriodInitializationConcurrencyProof;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_public_command_and_dependency_contract_is_narrow(): void
    {
        $method = new ReflectionMethod(FinancialPeriodInitializationService::class, 'initialize');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame(User::class, $parameters[0]->getType()?->getName());
        $this->assertSame(FinancialPeriod::class, $method->getReturnType()?->getName());

        $source = (string) file_get_contents(base_path('Modules/Finance/GeneralLedger/Services/FinancialPeriodInitializationService.php'));
        $this->assertStringNotContainsString('PeriodControlService', $source);
        $this->assertStringNotContainsString('PostingPeriodGuard', $source);
        $this->assertStringContainsString('FinancialPeriodAuthorizationService', $source);
        $this->assertStringContainsString('CurrentBusinessDateService', $source);
        $this->assertLessThan(strpos($source, 'FinancialPeriod::'), strpos($source, 'authorizeInitialization($actor)'));
    }

    public function test_first_initialization_uses_authorized_property_business_date_and_server_audit_values(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 02:03:04', 'UTC'));
        [$company, $property, $actor] = $this->authorizedContext('2026-12-31');

        $period = $this->service()->initialize($actor);

        $this->assertSame($property->id, $period->property_id);
        $this->assertSame(2026, $period->period_year);
        $this->assertSame(12, $period->period_month);
        $this->assertSame(FinancialPeriodStatusEnum::Open, $period->status);
        $this->assertSame($actor->id, $period->opened_by);
        $this->assertSame($actor->id, $period->created_by);
        $this->assertSame($actor->id, $period->updated_by);
        $this->assertSame('2027-01-01 02:03:04', $period->opened_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($period->closing_snapshot_at);
        $this->assertNull($period->closed_at);
        $this->assertNull($period->closed_by);
        $this->assertSame(1, $this->historyFor($property)->count());
        $this->assertDatabaseMissing('gl_financial_periods', ['property_id' => $property->id, 'period_month' => 11]);
        $this->assertDatabaseMissing('gl_financial_periods', ['property_id' => $property->id, 'period_month' => 1]);
        $this->assertSame($company->id, $property->company_id);
    }

    public function test_exact_retry_returns_same_row_without_mutation(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $first = $this->service()->initialize($actor);
        $before = (array) DB::table('gl_financial_periods')->where('id', $first->id)->first();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-03-04 05:06:07', 'UTC'));
        $retry = $this->service()->initialize($actor);
        $after = (array) DB::table('gl_financial_periods')->where('id', $first->id)->first();

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($before, $after);
        $this->assertSame(1, $this->historyFor($property)->count());
    }

    public function test_missing_business_date_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext(businessDate: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CurrentBusinessDateService::ERROR_NOT_INITIALIZED);

        try {
            $this->service()->initialize($actor);
        } finally {
            $this->assertSame(0, $this->historyFor($property)->count());
        }
    }

    public function test_closed_business_date_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext(businessDate: null);
        $this->businessDate($property, $actor, '2026-06-21', PropertyBusinessDateStatusEnum::Closed);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CurrentBusinessDateService::ERROR_OPEN_UNAVAILABLE);

        try {
            $this->service()->initialize($actor);
        } finally {
            $this->assertSame(0, $this->historyFor($property)->count());
        }
    }

    public function test_missing_permission_is_rejected_before_any_financial_period_query(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $actor->revokePermissionTo(FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        try {
            $this->service()->initialize($actor);
            $this->fail('Initialization without permission must fail closed.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(FinancialPeriodAuthorizationService::FAILURE_MESSAGE, $exception->getMessage());
        } finally {
            $queries = DB::connection()->getQueryLog();
            DB::connection()->disableQueryLog();
        }

        $this->assertFalse(collect($queries)->contains(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'gl_financial_periods')
        ));
        $this->assertSame(0, $this->historyFor($property)->count());
    }

    public function test_cross_property_context_cannot_initialize_another_property(): void
    {
        [$company, $propertyA, $actor] = $this->authorizedContext();
        $propertyB = $this->property($company);
        $this->businessDate($propertyB, $actor);
        $this->authenticate($actor, $company, $propertyB);

        try {
            $this->service()->initialize($actor);
            $this->fail('Cross-property initialization must fail closed.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(FinancialPeriodAuthorizationService::FAILURE_MESSAGE, $exception->getMessage());
        }

        $this->assertSame(0, $this->historyFor($propertyA)->count());
        $this->assertSame(0, $this->historyFor($propertyB)->count());
    }

    public function test_prior_period_history_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext('2026-06-21');
        $this->financialPeriod($property, $actor, 2026, 5);

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_future_period_history_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext('2026-06-21');
        $this->financialPeriod($property, $actor, 2026, 7);

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_multiple_period_history_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext('2026-06-21');
        $this->financialPeriod($property, $actor, 2026, 5);
        $this->financialPeriod($property, $actor, 2026, 6);

        $this->assertHistoryRejected($property, $actor, 2);
    }

    public function test_current_closing_period_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $this->financialPeriod($property, $actor, status: FinancialPeriodStatusEnum::Closing);

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_current_closed_period_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $this->financialPeriod($property, $actor, status: FinancialPeriodStatusEnum::Closed);

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_current_reopened_period_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $this->financialPeriod($property, $actor, status: FinancialPeriodStatusEnum::Reopened);

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_soft_deleted_history_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $period = $this->financialPeriod($property, $actor);
        $period->delete();

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_incomplete_opening_evidence_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $this->financialPeriod($property, $actor, attributes: ['opened_by' => null]);

        $this->assertHistoryRejected($property, $actor);
    }

    public function test_posting_guard_remains_missing_before_and_resolves_created_period_after_initialization(): void
    {
        [, , $actor] = $this->authorizedContext('2026-08-31');
        $guard = app(PostingPeriodGuard::class);

        try {
            $guard->assertPostingAllowed();
            $this->fail('Posting guard must reject a missing FinancialPeriod.');
        } catch (FinancialPeriodMissingException) {
            $this->assertTrue(true);
        }

        $period = $this->service()->initialize($actor);
        $result = $guard->assertPostingAllowed();

        $this->assertSame($period->id, $result->financialPeriodId);
        $this->assertSame(2026, $result->periodYear);
        $this->assertSame(8, $result->periodMonth);
    }

    public function test_query_order_locks_business_date_before_financial_period_history(): void
    {
        [, , $actor] = $this->authorizedContext();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        try {
            $this->service()->initialize($actor);
        } finally {
            $queries = DB::connection()->getQueryLog();
            DB::connection()->disableQueryLog();
        }

        $businessDateLock = null;
        $periodHistoryLock = null;
        foreach ($queries as $index => $query) {
            $sql = strtolower($query['query']);
            if ($businessDateLock === null && str_contains($sql, 'property_business_dates') && str_contains($sql, 'for update')) {
                $businessDateLock = $index;
            }
            if ($periodHistoryLock === null && str_contains($sql, 'gl_financial_periods') && str_contains($sql, 'for update')) {
                $periodHistoryLock = $index;
            }
        }

        $this->assertNotNull($businessDateLock);
        $this->assertNotNull($periodHistoryLock);
        $this->assertLessThan($periodHistoryLock, $businessDateLock);
    }

    public function test_post_lock_revalidation_rejects_a_business_date_that_became_closed(): void
    {
        [, $property, $actor, $businessDate] = $this->authorizedContext();
        DB::table('property_business_dates')->where('id', $businessDate->id)->update([
            'status' => PropertyBusinessDateStatusEnum::Closed->value,
            'is_open' => null,
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);

        $staleResolver = new class(app(CurrentPropertyService::class), $businessDate) extends CurrentBusinessDateService
        {
            public function __construct(CurrentPropertyService $currentProperty, private readonly PropertyBusinessDate $stale)
            {
                parent::__construct($currentProperty);
            }

            public function getActiveBusinessDate(): PropertyBusinessDate
            {
                return $this->stale;
            }
        };

        $service = new FinancialPeriodInitializationService(
            app(FinancialPeriodAuthorizationService::class),
            $staleResolver,
        );

        try {
            $service->initialize($actor);
            $this->fail('A Business Date closed before lock acquisition must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(FinancialPeriodInitializationService::ERROR_BUSINESS_DATE_CHANGED_OR_CLOSED, $exception->getMessage());
        }

        $this->assertSame(0, $this->historyFor($property)->count());
    }

    private function authorizedContext(?string $businessDate = '2026-06-21'): array
    {
        $company = CompanyFactory::new()->create(['is_active' => true]);
        $property = $this->property($company);
        $actor = UserFactory::new()->withProperty($property)->create([
            'is_active' => true,
            'is_system_admin' => false,
        ]);

        Permission::firstOrCreate([
            'name' => FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo(FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION);
        $this->authenticate($actor, $company, $property);

        $row = $businessDate === null ? null : $this->businessDate($property, $actor, $businessDate);

        return [$company, $property, $actor, $row];
    }

    private function property(Company $company): Property
    {
        return PropertyFactory::new()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    private function businessDate(
        Property $property,
        User $actor,
        string $date = '2026-06-21',
        PropertyBusinessDateStatusEnum $status = PropertyBusinessDateStatusEnum::Open,
    ): PropertyBusinessDate {
        return PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => $date,
            'status' => $status,
            'is_open' => $status === PropertyBusinessDateStatusEnum::Open ? true : null,
            'opened_at' => now(),
            'opened_by' => $actor->id,
        ]);
    }

    private function authenticate(User $actor, Company $company, Property $property): void
    {
        app(CurrentPropertyService::class)->clear();
        session()->forget(['active_property_id', 'current_property_id']);
        session([
            'active_company_id' => $company->id,
            'current_property_id' => $property->id,
        ]);
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        auth()->login($actor);
        $this->actingAs($actor);
    }

    private function financialPeriod(
        Property $property,
        User $actor,
        int $year = 2026,
        int $month = 6,
        FinancialPeriodStatusEnum $status = FinancialPeriodStatusEnum::Open,
        array $attributes = [],
    ): FinancialPeriod {
        $period = new FinancialPeriod;
        $period->forceFill(array_merge([
            'property_id' => $property->id,
            'period_year' => $year,
            'period_month' => $month,
            'status' => $status,
            'opened_at' => now(),
            'opened_by' => $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'closing_snapshot_at' => null,
            'closed_at' => null,
            'closed_by' => null,
        ], $attributes));
        $period->save();

        return $period;
    }

    private function historyFor(Property $property)
    {
        return FinancialPeriod::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->get();
    }

    private function assertHistoryRejected(Property $property, User $actor, int $expectedCount = 1): void
    {
        try {
            $this->service()->initialize($actor);
            $this->fail('Initialization after FinancialPeriod history must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                FinancialPeriodInitializationService::ERROR_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY,
                $exception->getMessage(),
            );
        }

        $this->assertSame($expectedCount, $this->historyFor($property)->count());
    }

    private function service(): FinancialPeriodInitializationService
    {
        return app(FinancialPeriodInitializationService::class);
    }
}

trait FinancialPeriodInitializationConcurrencyProof
{
    public function test_two_real_workers_converge_to_one_financial_period_row(): void
    {
        Permission::firstOrCreate([
            'name' => FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = CompanyFactory::new()->create(['is_active' => true]);
        $property = PropertyFactory::new()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $actorA = $this->authorizedActor($property);
        $actorB = $this->authorizedActor($property);
        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-09-30',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_at' => now(),
            'opened_by' => $actorA->id,
        ]);

        $results = $this->spawnWorkers($company, $property, [$actorA, $actorB]);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame(1, $result['row_count']);
            $this->assertSame(2026, $result['period_year']);
            $this->assertSame(9, $result['period_month']);
        }

        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
        $this->assertSame($results[0]['financial_period_id'], $results[1]['financial_period_id']);

        $rows = FinancialPeriod::withoutGlobalScopes()->where('property_id', $property->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(2026, $rows->first()->period_year);
        $this->assertSame(9, $rows->first()->period_month);
    }

    private function authorizedActor(Property $property): User
    {
        $actor = UserFactory::new()->withProperty($property)->create([
            'is_active' => true,
            'is_system_admin' => false,
        ]);
        $actor->givePermissionTo(FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION);

        return $actor;
    }

    private function spawnWorkers(Company $company, Property $property, array $actors): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fp-b4b-conc-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);

        $worker = __DIR__.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'FinancialPeriodInitializationWorker.php';
        $barrier = $directory.DIRECTORY_SEPARATOR.'barrier';
        $runId = (string) Str::ulid();
        $processes = [];
        $created = [];

        try {
            foreach ($actors as $index => $actor) {
                $workerId = 'w'.$index;
                $argsFile = $directory.DIRECTORY_SEPARATOR."args-{$workerId}.json";
                $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$workerId}.json";
                $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$workerId}.txt";
                array_push($created, $argsFile, $resultFile, $stderrFile);

                file_put_contents($argsFile, json_encode([
                    'worker_id' => $workerId,
                    'run_id' => $runId,
                    'result_file' => $resultFile,
                    'barrier' => $barrier,
                    'property_id' => $property->id,
                    'company_id' => $company->id,
                    'actor_id' => $actor->id,
                ]));

                $command = sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($worker), escapeshellarg($argsFile));
                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $process = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ivorq_testing',
                ]));

                if (! is_resource($process)) {
                    $this->fail('Unable to spawn FinancialPeriod initialization worker.');
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
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr_file'])
                    ? trim((string) file_get_contents($process['stderr_file']))
                    : '';
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach (['ready-w0', 'ready-w1'] as $name) {
                $created[] = $barrier.'-'.$name.'.json';
            }
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
                $observed = (int) ($status['exitcode'] ?? -1);
                $closed = proc_close($process);

                return $observed >= 0 ? $observed : $closed;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        return 124;
    }
}

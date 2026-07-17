<?php

namespace Tests\Postgres\Operations\NightAudit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class NightAuditRunConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    public function test_two_real_authorized_start_workers_converge_to_one_active_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 01:00:00', 'UTC'));

        foreach ([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
        $property = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-07-17',
            'timezone_snapshot' => 'Asia/Makassar',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_by' => (string) Str::ulid(),
            'opened_at' => Carbon::parse('2026-07-17 00:00:00', 'UTC'),
        ]);
        $actors = [
            $this->authorizedActor($property),
            $this->authorizedActor($property),
        ];

        $results = $this->spawnWorkers($company, $property, $businessDate, $actors);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame(1, $result['row_count']);
            $this->assertSame(1, $result['attempt_number']);
            $this->assertSame(NightAuditRunStatusEnum::InProgress->value, $result['status']);
            $this->assertSame(1, $result['transaction_attempts']);
        }

        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
        $this->assertSame($results[0]['night_audit_run_id'], $results[1]['night_audit_run_id']);
        $this->assertSame($results[0]['started_by'], $results[1]['started_by']);
        $this->assertSame($results[0]['started_at'], $results[1]['started_at']);
        $this->assertSame(1, NightAuditRun::withoutGlobalScopes()->where('status', NightAuditRunStatusEnum::InProgress->value)->count());
    }

    private function authorizedActor(Property $property): User
    {
        $actor = \Database\Factories\UserFactory::new()->withProperty($property)->create(['is_active' => true]);
        $actor->givePermissionTo([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
        ]);

        return $actor;
    }

    /**
     * @param User[] $actors
     * @return array<int, array<string, mixed>>
     */
    private function spawnWorkers(Company $company, Property $property, PropertyBusinessDate $businessDate, array $actors): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'na-a1-conc-' . Str::lower(Str::random(8));
        mkdir($dir, 0700, true);

        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'NightAuditRunStartWorker.php';
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';
        $runId = (string) Str::ulid();
        $processes = [];
        $created = [];

        try {
            foreach ($actors as $i => $actor) {
                $workerId = 'w' . $i;
                $argsFile = $dir . DIRECTORY_SEPARATOR . "args-{$workerId}.json";
                $resultFile = $dir . DIRECTORY_SEPARATOR . "result-{$workerId}.json";
                $stderrFile = $dir . DIRECTORY_SEPARATOR . "stderr-{$workerId}.txt";
                array_push($created, $argsFile, $resultFile, $stderrFile);

                file_put_contents($argsFile, json_encode([
                    'worker_id' => $workerId,
                    'run_id' => $runId,
                    'result_file' => $resultFile,
                    'barrier' => $barrier,
                    'property_id' => $property->id,
                    'company_id' => $company->id,
                    'property_business_date_id' => $businessDate->id,
                    'actor_id' => $actor->id,
                    'test_now' => '2026-07-17T01:00:00Z',
                ]));

                $command = sprintf('%s %s %s', PHP_BINARY, escapeshellarg($worker), escapeshellarg($argsFile));
                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $proc = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ivorq_testing',
                ]));
                if (! is_resource($proc)) {
                    $this->fail('Unable to spawn NA-A1 worker.');
                }
                fclose($pipes[0]);

                $processes[] = compact('proc', 'resultFile', 'stderrFile');
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['proc'], 15);
                $decoded = is_file($process['resultFile'])
                    ? json_decode((string) file_get_contents($process['resultFile']), true)
                    : ['error' => 'missing result file'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderrFile']) ? trim((string) file_get_contents($process['stderrFile'])) : '';
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach (['ready-w0', 'ready-w1'] as $name) {
                $created[] = $barrier . '-' . $name . '.json';
            }
            foreach ($created as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
            Carbon::setTestNow();
        }
    }

    private function waitForProcess($proc, int $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $status = proc_get_status($proc);
            if (! ($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                proc_close($proc);

                return $exitCode;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($proc);
        proc_close($proc);

        return 124;
    }
}

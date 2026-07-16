<?php

namespace Tests\Postgres\Foundation;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class PropertyBusinessDateInitializationConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    public function test_two_real_workers_converge_to_one_business_date_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 23:30:00', 'UTC'));

        Permission::firstOrCreate(['name' => PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
        $property = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $actorA = $this->authorizedActor($property);
        $actorB = $this->authorizedActor($property);

        $results = $this->spawnWorkers($company, $property, [$actorA, $actorB]);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame(1, $result['row_count']);
            $this->assertSame('2026-07-17', $result['business_date']);
        }

        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
        $this->assertSame($results[0]['property_business_date_id'], $results[1]['property_business_date_id']);

        $row = PropertyBusinessDate::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, PropertyBusinessDate::withoutGlobalScopes()->count());
        $this->assertContains($row->opened_by, [$actorA->id, $actorB->id]);
        $this->assertSame($row->opened_by, $results[0]['opened_by']);
        $this->assertSame($row->opened_by, $results[1]['opened_by']);
        $this->assertSame('Asia/Makassar', $row->timezone_snapshot);
        $this->assertSame('2026-07-17', $row->business_date->format('Y-m-d'));
    }

    private function authorizedActor(Property $property): User
    {
        $actor = \Database\Factories\UserFactory::new()->withProperty($property)->create(['is_active' => true]);
        $actor->givePermissionTo(PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION);

        return $actor;
    }

    private function spawnWorkers(Company $company, Property $property, array $actors): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bd-a1-conc-' . Str::lower(Str::random(8));
        mkdir($dir, 0700, true);

        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'PropertyBusinessDateInitializationWorker.php';
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
                    'actor_id' => $actor->id,
                    'test_now' => '2026-07-16T23:30:00Z',
                ]));

                $command = sprintf('%s %s %s', PHP_BINARY, escapeshellarg($worker), escapeshellarg($argsFile));
                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $proc = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ivorq_testing',
                ]));
                if (! is_resource($proc)) {
                    $this->fail('Unable to spawn BD-A1 worker.');
                }
                fclose($pipes[0]);

                $processes[] = [
                    'proc' => $proc,
                    'result_file' => $resultFile,
                    'stderr_file' => $stderrFile,
                ];
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['proc'], 12);
                $decoded = is_file($process['result_file'])
                    ? json_decode((string) file_get_contents($process['result_file']), true)
                    : ['error' => 'missing result file'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr_file']) ? trim((string) file_get_contents($process['stderr_file'])) : '';
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

<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Support\Str;
use Tests\TestCase;

class HousekeepingRoomReadinessIsolatedConcurrencyProofTest extends TestCase
{
    public function test_housekeeping_room_readiness_transitions_are_concurrency_safe(): void
    {
        $runId = 'hkb' . strtolower(Str::random(6));
        $result = $this->runCoordinator($runId);

        $this->assertTrue($result['db_created'] ?? false, 'Disposable database must be created.');
        $this->assertTrue($result['migrations_ok'] ?? false, 'Disposable database migrations must succeed.');
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null, 'ivorq_testing must remain untouched by disposable lifecycle.');
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        foreach (['duplicate_concurrency', 'release_concurrency'] as $scenario) {
            $proof = $result[$scenario] ?? [];
            $this->assertTrue($proof['pid_different'] ?? false, "{$scenario} workers must have distinct OS PIDs.");
            $this->assertTrue($proof['pg_different'] ?? false, "{$scenario} workers must have distinct PostgreSQL backend PIDs.");
            $this->assertContains('CONTROLLED_FAILURE', $proof['outcomes'] ?? []);

            $this->assertGreaterThan(0, $proof['worker_a_pid'] ?? -1, "{$scenario} worker A OS PID must be a positive integer.");
            $this->assertGreaterThan(0, $proof['worker_b_pid'] ?? -1, "{$scenario} worker B OS PID must be a positive integer.");
            $this->assertGreaterThan(0, $proof['worker_a_pg_backend_pid'] ?? -1, "{$scenario} worker A PG backend PID must be a positive integer.");
            $this->assertGreaterThan(0, $proof['worker_b_pg_backend_pid'] ?? -1, "{$scenario} worker B PG backend PID must be a positive integer.");
            $this->assertNotSame(
                $proof['worker_a_pid'],
                $proof['worker_b_pid'],
                "{$scenario} worker OS PIDs must differ: {$proof['worker_a_pid']} vs {$proof['worker_b_pid']}"
            );
            $this->assertNotSame(
                $proof['worker_a_pg_backend_pid'],
                $proof['worker_b_pg_backend_pid'],
                "{$scenario} worker PG backend PIDs must differ: {$proof['worker_a_pg_backend_pid']} vs {$proof['worker_b_pg_backend_pid']}"
            );
        }

        $duplicateOutcomes = $result['duplicate_concurrency']['outcomes'] ?? [];
        $this->assertSame(1, count(array_filter($duplicateOutcomes, fn (string $o) => $o === 'CLEANING_STARTED')));
        $this->assertSame('cleaning', $result['duplicate_concurrency']['final_readiness_state'] ?? '');
        $this->assertSame(1, $result['duplicate_concurrency']['transition_evidence_count'] ?? -1);
        $this->assertSame(0, $result['duplicate_concurrency']['orphan_evidence_count'] ?? -1);

        $releaseOutcomes = $result['release_concurrency']['outcomes'] ?? [];
        $this->assertSame(1, count(array_filter($releaseOutcomes, fn (string $o) => $o === 'RELEASED_READY')));
        $this->assertSame('ready_for_sale', $result['release_concurrency']['final_readiness_state'] ?? '');
        $this->assertSame(2, $result['release_concurrency']['transition_evidence_count'] ?? -1);
        $this->assertSame(0, $result['release_concurrency']['orphan_evidence_count'] ?? -1);

        $this->assertTrue($result['db_dropped'] ?? false, 'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_hk_b_' . strtolower(Str::random(4));
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-hkb-conc-' . $runId . '-' . Str::random(4);
        @mkdir($barrierDir, 0700, true);

        try {
            $coordinatorScript = __DIR__ . '/Support/HousekeepingRoomReadinessConcurrencyCoordinator.php';
            $configFile = $barrierDir . DIRECTORY_SEPARATOR . 'coordinator-config.json';
            $resultFile = $barrierDir . DIRECTORY_SEPARATOR . 'coordinator-result.json';

            $pgsql = config('database.connections.pgsql');
            $config = [
                'db_name' => $dbName,
                'barrier_dir' => $barrierDir,
                'base_path' => base_path(),
                'db_host' => $pgsql['host'] ?? '127.0.0.1',
                'db_port' => (string) ($pgsql['port'] ?? '5432'),
                'db_user' => $pgsql['username'],
                'db_pass' => $pgsql['password'],
                'result_file' => $resultFile,
            ];

            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

            $cmd = PHP_BINARY . ' ' . escapeshellarg($coordinatorScript) . ' ' . escapeshellarg($configFile);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
            $process = proc_open($cmd, $descriptors, $pipes, base_path());
            if (! is_resource($process)) {
                return ['error' => 'FAILED_TO_START_COORDINATOR'];
            }

            fclose($pipes[0]);
            fclose($pipes[1]);

            $end = time() + 300;
            while (time() < $end) {
                $status = proc_get_status($process);
                if (! $status['running'] && file_exists($resultFile)) {
                    break;
                }
                if (file_exists($resultFile)) {
                    usleep(500000);
                    break;
                }
                usleep(100000);
            }

            if (file_exists($resultFile)) {
                $json = file_get_contents($resultFile);
                $result = json_decode($json, true) ?: ['error' => 'PARSE_ERROR', 'raw' => $json];
            } else {
                $result = ['error' => 'TIMEOUT_NO_RESULT'];
            }

            @proc_close($process);

            return $result;
        } finally {
            $files = @glob($barrierDir . DIRECTORY_SEPARATOR . '*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($barrierDir);
        }
    }
}

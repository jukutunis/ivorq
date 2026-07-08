<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Str;
use Tests\TestCase;

class FrontDeskAssignmentIsolatedConcurrencyProofTest extends TestCase
{
    public function test_assignment_and_check_in_are_concurrency_safe_in_isolated_postgresql_processes(): void
    {
        $runId = 'fda2' . strtolower(Str::random(6));
        $result = $this->runCoordinator($runId);

        $this->assertTrue($result['db_created'] ?? false, 'Disposable database must be created.');
        $this->assertTrue($result['migrations_ok'] ?? false, 'Disposable database migrations must succeed.');
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        $assignment = $result['assignment_concurrency'] ?? [];
        $this->assertTrue($assignment['pid_different'] ?? false);
        $this->assertTrue($assignment['pg_different'] ?? false);
        $this->assertSame('rooms:' . ($result['assignment_fixture']['room_id'] ?? ''), $assignment['lock_identity'] ?? null);
        $this->assertContains('ROOM_ASSIGNED', $assignment['outcomes'] ?? []);
        $this->assertContains('CONTROLLED_FAILURE', $assignment['outcomes'] ?? []);
        $this->assertSame(1, $assignment['final_stay_count'] ?? -1);
        $this->assertSame(1, $assignment['final_active_room_occupancy_count'] ?? -1);
        $this->assertSame(1, $assignment['final_assignment_count'] ?? -1);
        $this->assertSame(0, $assignment['orphan_stay_count'] ?? -1);
        $this->assertSame(0, $assignment['orphan_assignment_count'] ?? -1);
        $this->assertTrue(($assignment['worker_a']['lock_attempted'] ?? false) && ($assignment['worker_b']['lock_attempted'] ?? false));

        $checkIn = $result['check_in_concurrency'] ?? [];
        $this->assertTrue($checkIn['pid_different'] ?? false);
        $this->assertTrue($checkIn['pg_different'] ?? false);
        $this->assertSame('front_desk_stays:' . ($result['check_in_fixture']['stay_id'] ?? ''), $checkIn['lock_identity'] ?? null);
        $this->assertContains('IN_HOUSE', $checkIn['outcomes'] ?? []);
        $this->assertContains('CONTROLLED_FAILURE', $checkIn['outcomes'] ?? []);
        $this->assertSame(1, $checkIn['final_in_house_count'] ?? -1);
        $this->assertSame(1, $checkIn['final_assignment_count'] ?? -1);
        $this->assertSame(1, $checkIn['final_active_room_occupancy_count'] ?? -1);
        $this->assertSame(0, $checkIn['orphan_stay_count'] ?? -1);
        $this->assertSame(0, $checkIn['orphan_assignment_count'] ?? -1);

        $this->assertTrue($result['db_dropped'] ?? false, 'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_fd_a2_' . $runId . '_' . strtolower(Str::random(4));
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-fd-a2-conc-' . $runId . '-' . Str::random(4);
        @mkdir($barrierDir, 0700, true);

        try {
            $coordinatorScript = __DIR__ . '/Support/FrontDeskAssignmentConcurrencyCoordinator.php';
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

            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($coordinatorScript) . ' ' . escapeshellarg($configFile), [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, base_path());
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

            $result = file_exists($resultFile)
                ? (json_decode(file_get_contents($resultFile), true) ?: ['error' => 'PARSE_ERROR'])
                : ['error' => 'TIMEOUT_NO_RESULT'];
            @proc_close($process);

            return $result;
        } finally {
            foreach ((@glob($barrierDir . DIRECTORY_SEPARATOR . '*') ?: []) as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($barrierDir);
        }
    }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Str;
use Tests\TestCase;

class FrontDeskRoomMoveIsolatedConcurrencyProofTest extends TestCase
{
    public function test_room_move_is_concurrency_safe_in_isolated_postgresql_processes(): void
    {
        $result = $this->runCoordinator('fda3' . strtolower(Str::random(6)));

        $this->assertTrue($result['db_created'] ?? false);
        $this->assertTrue($result['migrations_ok'] ?? false);
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        $sameTarget = $result['same_target_room_move'] ?? [];
        $this->assertTrue($sameTarget['pid_different'] ?? false);
        $this->assertTrue($sameTarget['pg_different'] ?? false);
        $this->assertSame('rooms:' . ($result['same_target_fixture']['target_room_id'] ?? ''), $sameTarget['lock_identity'] ?? null);
        $this->assertContains('ROOM_MOVED', $sameTarget['outcomes'] ?? []);
        $this->assertContains('CONTROLLED_FAILURE', $sameTarget['outcomes'] ?? []);
        $this->assertSame(1, $sameTarget['final_active_target_room_occupancy_count'] ?? -1);
        $this->assertSame(1, $sameTarget['final_room_move_assignment_count'] ?? -1);
        $this->assertSame(2, $sameTarget['historical_initial_assignment_count'] ?? -1);
        $this->assertSame(0, $sameTarget['orphan_assignment_count'] ?? -1);

        $duplicate = $result['duplicate_same_stay_move'] ?? [];
        $this->assertTrue($duplicate['pid_different'] ?? false);
        $this->assertTrue($duplicate['pg_different'] ?? false);
        $this->assertSame('front_desk_stays:' . ($result['duplicate_fixture']['stay_id'] ?? ''), $duplicate['lock_identity'] ?? null);
        $this->assertContains('ROOM_MOVED', $duplicate['outcomes'] ?? []);
        $this->assertContains('CONTROLLED_FAILURE', $duplicate['outcomes'] ?? []);
        $this->assertSame(1, $duplicate['final_active_target_room_occupancy_count'] ?? -1);
        $this->assertSame(1, $duplicate['final_room_move_assignment_count'] ?? -1);
        $this->assertSame(1, $duplicate['historical_initial_assignment_count'] ?? -1);
        $this->assertSame(0, $duplicate['orphan_assignment_count'] ?? -1);

        $this->assertTrue($result['db_dropped'] ?? false, 'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_fd_a3_' . $runId . '_' . strtolower(Str::random(4));
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-fd-a3-conc-' . $runId . '-' . Str::random(4);
        @mkdir($barrierDir, 0700, true);

        try {
            $configFile = $barrierDir . DIRECTORY_SEPARATOR . 'coordinator-config.json';
            $resultFile = $barrierDir . DIRECTORY_SEPARATOR . 'coordinator-result.json';
            $pgsql = config('database.connections.pgsql');
            file_put_contents($configFile, json_encode([
                'db_name' => $dbName,
                'barrier_dir' => $barrierDir,
                'base_path' => base_path(),
                'db_host' => $pgsql['host'] ?? '127.0.0.1',
                'db_port' => (string) ($pgsql['port'] ?? '5432'),
                'db_user' => $pgsql['username'],
                'db_pass' => $pgsql['password'],
                'result_file' => $resultFile,
            ], JSON_PRETTY_PRINT));

            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/Support/FrontDeskRoomMoveConcurrencyCoordinator.php') . ' ' . escapeshellarg($configFile), [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                return ['error' => 'FAILED_TO_START_COORDINATOR'];
            }
            fclose($pipes[0]); fclose($pipes[1]);

            $end = time() + 300;
            while (time() < $end) {
                $status = proc_get_status($process);
                if ((! $status['running'] && file_exists($resultFile)) || file_exists($resultFile)) {
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

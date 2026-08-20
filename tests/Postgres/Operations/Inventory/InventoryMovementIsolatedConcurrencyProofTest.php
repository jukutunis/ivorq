<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryMovementIsolatedConcurrencyProofTest extends TestCase
{
    public function test_two_independent_issue_posts_cannot_drive_controlled_quantity_negative(): void
    {
        $runId = 'iss' . strtolower(Str::random(6));
        $result = $this->runCoordinator($runId);

        $ic = $result['issue_concurrency'] ?? [];

        $this->assertEquals(true, $result['db_created'] ?? false, 'Generated database must be created');
        $this->assertEquals(true, $result['migrations_ok'] ?? false, 'Migrations must succeed');

        $wa = $ic['worker_a'] ?? [];
        $wb = $ic['worker_b'] ?? [];

        $this->assertTrue($ic['pid_different'] ?? false, 'Workers must have different OS PIDs');
        $this->assertTrue($ic['pg_different'] ?? false, 'Workers must have different PG backend PIDs');

        $outcomes = $ic['outcomes'] ?? [];
        $this->assertCount(2, $outcomes, 'Both workers must produce a result');

        // Exactly one POSTED, one CONTROLLED_FAILURE
        $postedCount = 0;
        $failureCount = 0;
        foreach ($outcomes as $o) {
            if ($o === 'POSTED') $postedCount++;
            if ($o === 'CONTROLLED_FAILURE') $failureCount++;
        }
        $this->assertEquals(1, $postedCount, 'Exactly one issue must succeed');
        $this->assertEquals(1, $failureCount, 'Exactly one issue must fail controlled');
        $this->assertGreaterThanOrEqual(0.000, (float) ($ic['final_net_quantity'] ?? -1));
        $this->assertEquals(4.000, (float) ($ic['final_net_quantity'] ?? -1), 'Final net quantity must be exactly 4.000');

        // Final controlled quantity assertion must be done in the coordinator result
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        $this->assertEquals(true, $result['db_dropped'] ?? false,
            'Generated database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_' . $runId . '_' . strtolower(Str::random(4));
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-iss-conc-' . $runId . '-' . Str::random(4);
        @mkdir($barrierDir, 0700, true);

        try {
            $coordinatorScript = __DIR__ . '/Support/InventoryMovementConcurrencyCoordinator.php';
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
            if (!is_resource($process)) {
                return ['error' => 'FAILED_TO_START_COORDINATOR'];
            }
            fclose($pipes[0]);
            fclose($pipes[1]);

            $end = time() + 300;
            while (time() < $end) {
                $status = proc_get_status($process);
                if (!$status['running'] && file_exists($resultFile)) { break; }
                if (file_exists($resultFile)) { usleep(500000); break; }
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
            foreach ($files as $f) { if (is_file($f)) { @unlink($f); } }
            @rmdir($barrierDir);
        }
    }
}

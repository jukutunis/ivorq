<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class ControlledGoodsReceiptIsolatedConcurrencyProofTest extends PostgresTestCase
{
    public function test_two_independent_receipt_posts_cannot_over_receive_same_purchase_order_line(): void
    {
        $runId = 'over-' . Str::random(6);
        $result = $this->runCoordinator($runId);

        $or = $result['over_receipt'] ?? ['outcome' => 'NOT_RUN'];

        $this->assertEquals(true, $result['db_created'], 'DB must be created. Result: ' . ($result['error'] ?? json_encode($result)));
        $this->assertEquals(true, $result['migrations_ok'], 'Migrations must succeed');
        $this->assertEquals(true, $result['db_dropped'], 'DB must be dropped');

        $wa = $or['worker_a'] ?? [];
        $wb = $or['worker_b'] ?? [];

        $this->assertTrue($or['pid_different'] ?? false, 'Workers must have different OS PIDs. OverReceipt result: ' . json_encode($or));
        $this->assertTrue($or['pg_different'] ?? false, 'Workers must have different PG backend PIDs');

        $outcomes = [$wa['outcome'] ?? '?', $wb['outcome'] ?? '?' ];
        $this->assertContains('POSTED', $outcomes, 'One worker must succeed: ' . json_encode($outcomes));

        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? ''));
    }

    public function test_two_independent_identical_receipt_posts_create_one_canonical_outcome(): void
    {
        $runId = 'dup-' . Str::random(6);
        $result = $this->runCoordinator($runId);

        $dup = $result['duplicate'] ?? ['outcome' => 'NOT_RUN'];

        $this->assertEquals(true, $result['db_created'], 'DB must be created. Result: ' . ($result['error'] ?? json_encode($result)));
        $this->assertEquals(true, $result['migrations_ok'], 'Migrations must succeed');
        $this->assertEquals(true, $result['db_dropped'], 'DB must be dropped');

        $wa = $dup['worker_a'] ?? [];
        $wb = $dup['worker_b'] ?? [];

        $this->assertTrue($dup['pid_different'] ?? false, 'Workers must have different OS PIDs');
        $this->assertTrue($dup['pg_different'] ?? false, 'Workers must have different PG backend PIDs');

        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? ''));
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_' . $runId . '_' . Str::random(4);
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-conc-' . $runId . '-' . Str::random(4);
        mkdir($barrierDir, 0700, true);

        try {
            $coordinatorScript = __DIR__ . '/Support/ControlledGoodsReceiptConcurrencyCoordinator.php';
            $configFile = $barrierDir . DIRECTORY_SEPARATOR . 'coordinator-config.json';
            $resultFile = $barrierDir . DIRECTORY_SEPARATOR . 'coordinator-result.json';

            $pgsql = config('database.connections.pgsql');
            $config = [
                'db_name' => $dbName,
                'barrier_dir' => $barrierDir,
                'base_path' => base_path(),
                'env_file' => base_path('.env'),
                'db_host' => $pgsql['host'] ?? '127.0.0.1',
                'db_port' => (string) ($pgsql['port'] ?? '5432'),
                'db_user' => $pgsql['username'],
                'db_pass' => $pgsql['password'],
                'pgsql_bin' => 'C:\Program Files\PostgreSQL\18\bin',
                'user_id' => (string) Str::ulid(),
                'approver_id' => (string) Str::ulid(),
                'receiver_id' => (string) Str::ulid(),
                'result_file' => $resultFile,
            ];

            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

            $cmd = PHP_BINARY . ' ' . escapeshellarg($coordinatorScript) . ' ' . escapeshellarg($configFile);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];

            $process = proc_open($cmd, $descriptors, $pipes, base_path());
            if (!is_resource($process)) {
                throw new \RuntimeException('Failed to start coordinator');
            }
            fclose($pipes[0]);
            fclose($pipes[1]);

            $end = time() + 300;
            while (time() < $end) {
                $status = proc_get_status($process);
                if (!$status['running'] && file_exists($resultFile)) { break; }
                if (file_exists($resultFile)) { usleep(100000); break; }
                usleep(100000);
            }

            if (file_exists($resultFile)) {
                $json = file_get_contents($resultFile);
                $result = json_decode($json, true) ?: ['error' => 'PARSE_ERROR'];
            } else {
                $result = ['error' => 'TIMEOUT'];
            }

            proc_close($process);
            return $result;

        } finally {
            $files = @glob($barrierDir . DIRECTORY_SEPARATOR . '*') ?: [];
            foreach ($files as $f) { if (is_file($f)) { @unlink($f); } }
            @rmdir($barrierDir);
        }
    }
}

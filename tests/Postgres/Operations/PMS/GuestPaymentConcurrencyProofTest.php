<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\TestCase;

class GuestPaymentConcurrencyProofTest extends TestCase
{
    public function test_guest_payment_lifecycle_is_concurrency_safe_in_isolated_postgresql_workers(): void
    {
        $runId = 'glfb' . strtolower(Str::random(6));
        $result = $this->runCoordinator($runId);

        $this->assertTrue($result['db_created'] ?? false, 'Disposable database must be created.');
        $this->assertTrue($result['migrations_ok'] ?? false, 'Disposable database migrations must succeed.');
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        $this->assertRecordingReplay($result['recording_replay'] ?? []);
        $this->assertPaymentNumberSafety($result['payment_number_safety'] ?? []);
        $this->assertAllocationReplay($result['allocation_replay'] ?? []);
        $this->assertOverAllocationRace($result['over_allocation_race'] ?? []);
        $this->assertValidSplitRace($result['valid_split_race'] ?? []);
        $this->assertDoubleReversalRace($result['double_reversal_race'] ?? []);
        $this->assertAllocationVersusReversalRace($result['allocation_versus_reversal_race'] ?? []);

        $this->assertTrue($result['db_dropped'] ?? false, 'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }

    private function assertWorkerProof(array $scenario): void
    {
        $this->assertTrue($scenario['pid_different'] ?? false, 'Workers must have distinct PHP PIDs: ' . json_encode($scenario));
        $this->assertTrue($scenario['pg_different'] ?? false, 'Workers must have distinct PostgreSQL backend PIDs: ' . json_encode($scenario));
        $this->assertNull($scenario['worker_a']['hidden_error'] ?? null, 'Worker A hidden error: ' . json_encode($scenario['worker_a'] ?? []));
        $this->assertNull($scenario['worker_b']['hidden_error'] ?? null, 'Worker B hidden error: ' . json_encode($scenario['worker_b'] ?? []));
    }

    private function assertRecordingReplay(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $this->assertSame(['PAYMENT_RECORDED', 'PAYMENT_RECORDED'], $scenario['outcomes'] ?? []);
        $this->assertSame(1, $scenario['payment_count'] ?? -1);
        $this->assertSame(1, $scenario['payment_number_count'] ?? -1);
        $this->assertSame($scenario['worker_a']['payment_id'] ?? 'a', $scenario['worker_b']['payment_id'] ?? 'b');
    }

    private function assertPaymentNumberSafety(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $this->assertSame(['PAYMENT_RECORDED', 'PAYMENT_RECORDED'], $scenario['outcomes'] ?? []);
        $this->assertSame(2, $scenario['payment_count'] ?? -1);
        $this->assertSame(2, $scenario['payment_number_count'] ?? -1);
        $this->assertNotSame($scenario['worker_a']['payment_number'] ?? 'x', $scenario['worker_b']['payment_number'] ?? 'x');
    }

    private function assertAllocationReplay(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $this->assertSame(['PAYMENT_ALLOCATED', 'PAYMENT_ALLOCATED'], $scenario['outcomes'] ?? []);
        $this->assertSame(1, $scenario['allocation_count'] ?? -1);
        $this->assertSame(1, $scenario['payment_item_count'] ?? -1);
        $this->assertSame($scenario['worker_a']['allocation_id'] ?? 'a', $scenario['worker_b']['allocation_id'] ?? 'b');
        $this->assertSame('50.00', $scenario['folio_total_payments'] ?? null);
        $this->assertSame('-50.00', $scenario['folio_balance'] ?? null);
    }

    private function assertOverAllocationRace(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $outcomes = $scenario['outcomes'] ?? [];
        sort($outcomes);
        $this->assertSame(['OVER_ALLOCATION', 'PAYMENT_ALLOCATED'], $outcomes);
        $this->assertSame(1, $scenario['allocation_count'] ?? -1);
        $this->assertSame(1, $scenario['payment_item_count'] ?? -1);
        $this->assertSame('60.00', $scenario['active_allocation_total'] ?? null);
    }

    private function assertValidSplitRace(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $this->assertSame(['PAYMENT_ALLOCATED', 'PAYMENT_ALLOCATED'], $scenario['outcomes'] ?? []);
        $this->assertSame(2, $scenario['allocation_count'] ?? -1);
        $this->assertSame(2, $scenario['payment_item_count'] ?? -1);
        $this->assertSame('100.00', $scenario['active_allocation_total'] ?? null);
        $this->assertSame('FULLY_ALLOCATED', $scenario['payment_status'] ?? null);
        $this->assertSame('60.00', $scenario['folio_a_total_payments'] ?? null);
        $this->assertSame('40.00', $scenario['folio_b_total_payments'] ?? null);
    }

    private function assertDoubleReversalRace(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $this->assertSame(['ALLOCATION_REVERSED', 'ALLOCATION_REVERSED'], $scenario['outcomes'] ?? []);
        $this->assertSame(1, $scenario['reversal_count'] ?? -1);
        $this->assertSame(1, $scenario['reversal_item_count'] ?? -1);
        $this->assertSame($scenario['worker_a']['reversal_id'] ?? 'a', $scenario['worker_b']['reversal_id'] ?? 'b');
        $this->assertSame('RECORDED', $scenario['payment_status'] ?? null);
        $this->assertSame('0.00', $scenario['folio_total_payments'] ?? null);
    }

    private function assertAllocationVersusReversalRace(array $scenario): void
    {
        $this->assertWorkerProof($scenario);
        $outcomes = $scenario['outcomes'] ?? [];
        sort($outcomes);
        $this->assertSame(['ALLOCATION_REVERSED', 'PAYMENT_ALLOCATED'], $outcomes);
        $this->assertSame(2, $scenario['allocation_count'] ?? -1);
        $this->assertSame(1, $scenario['reversal_count'] ?? -1);
        $this->assertSame('30.00', $scenario['active_allocation_total'] ?? null);
        $this->assertSame('PARTIALLY_ALLOCATED', $scenario['payment_status'] ?? null);
        $this->assertSame($scenario['fresh_total_payments'] ?? 'x', $scenario['cached_total_payments'] ?? 'y');
        $this->assertSame($scenario['fresh_balance'] ?? 'x', $scenario['cached_balance'] ?? 'y');
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_glf_b_' . $runId . '_' . strtolower(Str::random(4));
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-glfb-conc-' . $runId . '-' . Str::random(4);
        @mkdir($barrierDir, 0700, true);

        try {
            $coordinatorScript = __DIR__ . '/Support/GuestPaymentConcurrencyCoordinator.php';
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

            $process = proc_open(
                PHP_BINARY . ' ' . escapeshellarg($coordinatorScript) . ' ' . escapeshellarg($configFile),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
                $pipes,
                base_path()
            );

            if (!is_resource($process)) {
                return ['error' => 'FAILED_TO_START_COORDINATOR'];
            }

            fclose($pipes[0]);
            fclose($pipes[1]);

            $end = time() + 360;
            while (time() < $end) {
                $status = proc_get_status($process);
                if (!$status['running'] && file_exists($resultFile)) {
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

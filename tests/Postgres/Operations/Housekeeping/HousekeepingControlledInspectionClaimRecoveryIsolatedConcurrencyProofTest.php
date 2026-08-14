<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Support\Str;
use Tests\TestCase;

class HousekeepingControlledInspectionClaimRecoveryIsolatedConcurrencyProofTest extends TestCase
{
    public function test_two_real_workers_leave_one_coherent_recovery_and_immutable_original_claim(): void
    {
        for ($run = 0; $run < 2; $run++) {
            $result = $this->runCoordinator();
            $this->assertTrue($result['db_created'] ?? false);
            $this->assertTrue($result['migrations_ok'] ?? false);
            $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
            $this->assertNull($result['error_code'] ?? null);
            $this->assertNotSame($result['worker_a']['pid'] ?? 0, $result['worker_b']['pid'] ?? 0);
            $this->assertNotSame($result['worker_a']['pg_backend_pid'] ?? 0, $result['worker_b']['pg_backend_pid'] ?? 0);
            $this->assertEqualsCanonicalizing(['RECOVERED', 'CONTROLLED_REJECTION'], [
                $result['worker_a']['outcome'] ?? null, $result['worker_b']['outcome'] ?? null,
            ]);
            $this->assertSame(0, $result['worker_a']['transaction_level'] ?? -1);
            $this->assertSame(0, $result['worker_b']['transaction_level'] ?? -1);
            $this->assertSame(1, $result['recovery_count'] ?? -1);
            $this->assertSame(1, $result['audit_count'] ?? -1);
            $this->assertTrue($result['original_fields_unchanged'] ?? false);
            $this->assertTrue($result['one_effective_claimant'] ?? false);
            $this->assertTrue($result['db_dropped'] ?? false);
            $serialized = strtolower(json_encode($result, JSON_THROW_ON_ERROR));
            foreach (['db_pass', 'password', 'source_hash', 'idempotency_key', 'email', 'phone', 'error_message'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $serialized);
            }
        }
    }

    private function runCoordinator(): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq-p19-'.Str::lower(Str::random(10));
        mkdir($directory, 0700, true);
        $configFile = $directory.DIRECTORY_SEPARATOR.'config.json';
        $resultFile = $directory.DIRECTORY_SEPARATOR.'result.json';
        $pgsql = config('database.connections.pgsql');
        file_put_contents($configFile, json_encode([
            'mode' => 'coordinator',
            'db_name' => 'ivorq_concurrency_hk_p19_'.Str::lower(Str::random(8)),
            'barrier_dir' => $directory,
            'base_path' => base_path(),
            'db_host' => $pgsql['host'],
            'db_port' => (string) $pgsql['port'],
            'db_user' => $pgsql['username'],
            'db_pass' => $pgsql['password'],
            'result_file' => $resultFile,
        ], JSON_THROW_ON_ERROR));
        try {
            $script = __DIR__.'/Support/HousekeepingControlledInspectionClaimRecoveryWorker.php';
            $process = proc_open([PHP_BINARY, $script, $configFile], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                return ['error_code' => 'COORDINATOR_START_FAILED'];
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            for ($attempt = 0; $attempt < 4800 && ! is_file($resultFile); $attempt++) {
                usleep(100000);
            }
            if (! is_file($resultFile)) {
                proc_terminate($process);
                proc_close($process);

                return ['error_code' => 'COORDINATOR_TIMEOUT'];
            }
            $result = json_decode((string) file_get_contents($resultFile), true) ?: ['error_code' => 'RESULT_PARSE_FAILED'];
            proc_close($process);

            return $result;
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }
}

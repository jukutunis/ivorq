<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Support\Str;
use Tests\TestCase;

class HousekeepingControlledInspectionClaimSegregationIsolatedConcurrencyProofTest extends TestCase
{
    public function test_real_workers_prove_claim_replay_segregation_terminal_ownership_and_property_isolation(): void
    {
        $result = $this->runCoordinator();
        $this->assertTrue($result['db_created'] ?? false);
        $this->assertTrue($result['migrations_ok'] ?? false);
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error_code'] ?? null, 'Coordinator stage: ' . ($result['error_stage'] ?? 'unknown'));

        foreach (['competing_claimants', 'exact_replay', 'conflicting_key', 'cleaner_race', 'terminal_owner', 'pass_fail', 'cross_property', 'different_properties'] as $name) {
            $scenario = $result['scenarios'][$name] ?? [];
            $this->assertTrue($scenario['distinct_php_pids'] ?? false, "{$name}: distinct PHP PIDs required.");
            $this->assertTrue($scenario['distinct_pg_pids'] ?? false, "{$name}: distinct PostgreSQL backend PIDs required.");
            $this->assertSame(0, $scenario['running_after_result'] ?? -1);
            foreach (['worker_a', 'worker_b'] as $worker) {
                $this->assertGreaterThan(0, $scenario[$worker]['pid'] ?? 0);
                $this->assertGreaterThan(0, $scenario[$worker]['pg_backend_pid'] ?? 0);
                $this->assertSame(0, $scenario[$worker]['process_exit_code'] ?? -1);
                $this->assertNotSame('INTERNAL_FAILURE', $scenario[$worker]['outcome'] ?? null);
            }
        }

        $a = $result['scenarios']['competing_claimants'];
        $this->assertEqualsCanonicalizing(['CLAIMED', 'CONTROLLED_REJECTION'], [$a['worker_a']['outcome'], $a['worker_b']['outcome']]);
        $this->assertSame(1, $a['claim_count']);
        $this->assertSame(1, $a['audit_count']);

        $b = $result['scenarios']['exact_replay'];
        $this->assertEqualsCanonicalizing(['CLAIMED', 'REPLAYED'], [$b['worker_a']['outcome'], $b['worker_b']['outcome']]);
        $this->assertSame($b['worker_a']['inspection_id'], $b['worker_b']['inspection_id']);
        $this->assertSame(1, $b['audit_count']);

        $c = $result['scenarios']['conflicting_key'];
        $this->assertEqualsCanonicalizing(['CLAIMED', 'CONTROLLED_REJECTION'], [$c['worker_a']['outcome'], $c['worker_b']['outcome']]);
        $this->assertSame(1, $c['claim_count']);

        $d = $result['scenarios']['cleaner_race'];
        $this->assertEqualsCanonicalizing(['CLAIMED', 'CONTROLLED_REJECTION'], [$d['worker_a']['outcome'], $d['worker_b']['outcome']]);
        $this->assertNotSame($d['cleaner_id'], $d['claimant_id']);

        $e = $result['scenarios']['terminal_owner'];
        $this->assertEqualsCanonicalizing(['FAILED', 'CONTROLLED_REJECTION'], [$e['worker_a']['outcome'], $e['worker_b']['outcome']]);
        $this->assertSame('failed', $e['inspection_status']);

        $f = $result['scenarios']['pass_fail'];
        $this->assertContains($f['inspection_status'], ['passed', 'failed']);
        $this->assertContains('CONTROLLED_REJECTION', [$f['worker_a']['outcome'], $f['worker_b']['outcome']]);
        $this->assertSame(1, count(array_filter([$f['worker_a']['outcome'], $f['worker_b']['outcome']], fn (string $outcome): bool => in_array($outcome, ['PASSED', 'FAILED'], true))));

        $g = $result['scenarios']['cross_property'];
        $this->assertEqualsCanonicalizing(['CLAIMED', 'CONTROLLED_REJECTION'], [$g['worker_a']['outcome'], $g['worker_b']['outcome']]);
        $this->assertSame(1, $g['sibling_claim_count']);

        $h = $result['scenarios']['different_properties'];
        $this->assertSame('CLAIMED', $h['worker_a']['outcome']);
        $this->assertSame('CLAIMED', $h['worker_b']['outcome']);
        $this->assertGreaterThanOrEqual(1400, $h['worker_a']['duration_ms']);
        $this->assertLessThan(1400, $h['worker_b']['duration_ms']);
        $this->assertSame(2, $h['claim_count']);

        $this->assertSame(0, $result['orphan_worker_count'] ?? -1);
        $this->assertTrue($result['db_dropped'] ?? false);
        $serialized = strtolower(json_encode($result, JSON_THROW_ON_ERROR));
        foreach (['password', 'db_pass', 'source_hash', 'idempotency_key', 'error_message', 'error_class', 'sqlstate', 'email', 'phone'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    /** @return array<string, mixed> */
    private function runCoordinator(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-p17-' . Str::lower(Str::random(10));
        mkdir($directory, 0700, true);
        $database = 'ivorq_concurrency_hk_p17_' . Str::lower(Str::random(8));
        $configFile = $directory . DIRECTORY_SEPARATOR . 'coordinator-config.json';
        $resultFile = $directory . DIRECTORY_SEPARATOR . 'coordinator-result.json';
        $pgsql = config('database.connections.pgsql');
        file_put_contents($configFile, json_encode([
            'mode' => 'coordinator',
            'db_name' => $database,
            'barrier_dir' => $directory,
            'base_path' => base_path(),
            'db_host' => $pgsql['host'],
            'db_port' => (string) $pgsql['port'],
            'db_user' => $pgsql['username'],
            'db_pass' => $pgsql['password'],
            'result_file' => $resultFile,
        ], JSON_THROW_ON_ERROR));

        try {
            $script = __DIR__ . '/Support/HousekeepingControlledInspectionClaimSegregationWorker.php';
            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($configFile), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                return ['error_code' => 'COORDINATOR_START_FAILED'];
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $until = time() + 480;
            while (time() < $until && ! is_file($resultFile)) {
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
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }
}

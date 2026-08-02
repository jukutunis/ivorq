<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Support\Str;
use Tests\TestCase;

class HousekeepingCleaningInspectionReadinessIsolatedConcurrencyProofTest extends TestCase
{
    public function test_real_workers_converge_without_mixed_state_cross_property_serialization_or_orphans(): void
    {
        $result = $this->runCoordinator();

        $this->assertTrue($result['db_created'] ?? false);
        $this->assertTrue($result['migrations_ok'] ?? false);
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull(
            $result['error_code'] ?? null,
            'Coordinator must complete without a hidden internal failure. Stage: ' . ($result['error_stage'] ?? 'unknown'),
        );

        foreach (['start', 'completion', 'pass', 'fail', 'pass_fail', 'different_property'] as $name) {
            $scenario = $result['scenarios'][$name] ?? [];
            $this->assertTrue($scenario['distinct_php_pids'] ?? false, "{$name}: distinct PHP worker PIDs required.");
            $this->assertTrue($scenario['distinct_pg_pids'] ?? false, "{$name}: distinct PostgreSQL backend PIDs required.");
            $this->assertSame(0, $scenario['running_after_result'] ?? -1, "{$name}: workers must exit.");
            foreach (['worker_a', 'worker_b'] as $worker) {
                $proof = $scenario[$worker] ?? [];
                $this->assertGreaterThan(0, $proof['pid'] ?? 0);
                $this->assertGreaterThan(0, $proof['pg_backend_pid'] ?? 0);
                $this->assertNotSame('INTERNAL_FAILURE', $proof['outcome'] ?? null);
            }
        }

        $this->assertSame('in_progress', $result['scenarios']['start']['task_status'] ?? null);
        $this->assertSame(1, $result['scenarios']['start']['transition_count'] ?? -1);

        $this->assertSame('completed', $result['scenarios']['completion']['task_status'] ?? null);
        $this->assertSame(1, $result['scenarios']['completion']['transition_count'] ?? -1);
        $this->assertSame(1, $result['scenarios']['completion']['inspection_count'] ?? -1);

        $this->assertSame('passed', $result['scenarios']['pass']['inspection_status'] ?? null);
        $this->assertSame('ready_for_sale', $result['scenarios']['pass']['room_readiness'] ?? null);
        $this->assertSame(1, $result['scenarios']['pass']['transition_count'] ?? -1);

        $this->assertSame('failed', $result['scenarios']['fail']['inspection_status'] ?? null);
        $this->assertSame('waiting_cleaning', $result['scenarios']['fail']['room_readiness'] ?? null);
        $this->assertSame(1, $result['scenarios']['fail']['transition_count'] ?? -1);
        $this->assertSame(1, $result['scenarios']['fail']['rework_count'] ?? -1);

        $race = $result['scenarios']['pass_fail'];
        $this->assertContains($race['inspection_status'] ?? null, ['passed', 'failed']);
        $this->assertSame(1, $race['transition_count'] ?? -1);
        $this->assertSame(($race['inspection_status'] ?? null) === 'failed' ? 1 : 0, $race['rework_count'] ?? -1);
        $this->assertSame(
            ($race['inspection_status'] ?? null) === 'failed' ? 'waiting_cleaning' : 'ready_for_sale',
            $race['room_readiness'] ?? null,
        );
        $raceOutcomes = [$race['worker_a']['outcome'] ?? null, $race['worker_b']['outcome'] ?? null];
        $this->assertContains('CONTROLLED_REJECTION', $raceOutcomes);

        $different = $result['scenarios']['different_property'];
        $this->assertSame(1, $different['transition_count_a'] ?? -1);
        $this->assertSame(1, $different['transition_count_b'] ?? -1);
        $this->assertGreaterThanOrEqual(1400, $different['worker_a']['duration_ms'] ?? 0);
        $this->assertLessThan(1400, $different['worker_b']['duration_ms'] ?? PHP_INT_MAX);

        $this->assertSame(0, $result['mixed_state_count'] ?? -1);
        $this->assertSame(0, $result['orphan_worker_count'] ?? -1);
        $this->assertTrue($result['db_dropped'] ?? false);

        $serialized = strtolower(json_encode($result, JSON_THROW_ON_ERROR));
        foreach (['password', 'db_pass', 'token_hash', 'source_hash', 'confirmation_hash', 'error_message', 'error_class', 'sqlstate', 'guest_name', 'guest_email'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    /** @return array<string, mixed> */
    private function runCoordinator(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-p13-' . Str::lower(Str::random(10));
        mkdir($directory, 0700, true);
        $database = 'ivorq_concurrency_hk_p13_' . Str::lower(Str::random(8));
        $configFile = $directory . DIRECTORY_SEPARATOR . 'coordinator-config.json';
        $resultFile = $directory . DIRECTORY_SEPARATOR . 'coordinator-result.json';
        $pgsql = config('database.connections.pgsql');
        file_put_contents($configFile, json_encode([
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
            $script = __DIR__ . '/Support/P13CleaningInspectionConcurrencyCoordinator.php';
            $process = proc_open(
                PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($configFile),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            if (! is_resource($process)) {
                return ['error_code' => 'COORDINATOR_START_FAILED'];
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            $end = time() + 360;
            while (time() < $end && ! is_file($resultFile)) {
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
                    unlink($file);
                }
            }
            @rmdir($directory);
        }
    }
}

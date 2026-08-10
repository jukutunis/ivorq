<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Support\Str;
use Tests\TestCase;

class HousekeepingControlledDispatchAssignmentIsolatedConcurrencyProofTest extends TestCase
{
    public function test_real_workers_prove_assignment_reassignment_start_replay_isolation_and_nonserialization(): void
    {
        $result = $this->runCoordinator();
        $this->assertTrue($result['db_created'] ?? false);
        $this->assertTrue($result['migrations_ok'] ?? false);
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error_code'] ?? null, 'Coordinator stage: ' . ($result['error_stage'] ?? 'unknown'));

        foreach (['competing_initial', 'duplicate_initial', 'conflicting_idempotency', 'competing_reassignment', 'start_vs_reassign', 'response_loss', 'different_tasks_same_property', 'different_properties', 'cross_property'] as $name) {
            $scenario = $result['scenarios'][$name] ?? [];
            $this->assertTrue($scenario['distinct_php_pids'] ?? false, "{$name}: distinct PHP PIDs required.");
            $this->assertTrue($scenario['distinct_pg_pids'] ?? false, "{$name}: distinct PostgreSQL backend PIDs required.");
            $this->assertSame(0, $scenario['running_after_result'] ?? -1);
            $this->assertNotSame('INTERNAL_FAILURE', $scenario['worker_a']['outcome'] ?? null);
            $this->assertNotSame('INTERNAL_FAILURE', $scenario['worker_b']['outcome'] ?? null);
            $this->assertGreaterThan(0, $scenario['worker_a']['pid'] ?? 0);
            $this->assertGreaterThan(0, $scenario['worker_b']['pid'] ?? 0);
            $this->assertGreaterThan(0, $scenario['worker_a']['pg_backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $scenario['worker_b']['pg_backend_pid'] ?? 0);
            $this->assertSame(0, $scenario['worker_a']['process_exit_code'] ?? -1);
            $this->assertSame(0, $scenario['worker_b']['process_exit_code'] ?? -1);
        }

        $a = $result['scenarios']['competing_initial'];
        $this->assertSame('assigned', $a['task_status'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $a['active_count']);
        $this->assertSame(1, $a['audit_count']);
        $this->assertEqualsCanonicalizing(['ASSIGNED', 'CONTROLLED_REJECTION'], [$a['worker_a']['outcome'], $a['worker_b']['outcome']]);

        $b = $result['scenarios']['duplicate_initial'];
        $this->assertSame(1, $b['active_count']);
        $this->assertSame(1, $b['audit_count']);
        $this->assertSame($b['worker_a']['assignment_id'], $b['worker_b']['assignment_id']);
        $this->assertEqualsCanonicalizing(['ASSIGNED', 'REPLAYED'], [$b['worker_a']['outcome'], $b['worker_b']['outcome']]);

        $c = $result['scenarios']['conflicting_idempotency'];
        $this->assertSame(1, $c['active_count']);
        $this->assertEqualsCanonicalizing(['ASSIGNED', 'CONTROLLED_REJECTION'], [$c['worker_a']['outcome'], $c['worker_b']['outcome']]);

        $d = $result['scenarios']['competing_reassignment'];
        $this->assertSame(1, $d['active_count']);
        $this->assertSame('cancelled', $d['old_status']);
        $this->assertSame(2, $d['total_count']);
        $this->assertEqualsCanonicalizing(['REASSIGNED', 'CONTROLLED_REJECTION'], [$d['worker_a']['outcome'], $d['worker_b']['outcome']]);

        $e = $result['scenarios']['start_vs_reassign'];
        $outcomes = [$e['worker_a']['outcome'], $e['worker_b']['outcome']];
        $winnerByTaskStatus = [
            'in_progress' => 'STARTED',
            'assigned' => 'REASSIGNED',
        ];

        $this->assertSame(1, $e['active_count']);
        $this->assertNotNull($e['active_user_id']);
        $this->assertArrayHasKey($e['task_status'], $winnerByTaskStatus);
        $this->assertEqualsCanonicalizing(
            [$winnerByTaskStatus[$e['task_status']], 'CONTROLLED_REJECTION'],
            $outcomes
        );

        $f = $result['scenarios']['response_loss'];
        $this->assertSame(1, $f['active_count']);
        $this->assertSame(1, $f['audit_count']);
        $this->assertContains('COMMITTED_NO_RECEIPT', [$f['worker_a']['outcome'], $f['worker_b']['outcome']]);
        $this->assertContains('REPLAYED', [$f['worker_a']['outcome'], $f['worker_b']['outcome']]);

        $g = $result['scenarios']['different_tasks_same_property'];
        $this->assertSame(1, $g['active_count_a']);
        $this->assertSame(1, $g['active_count_b']);
        $this->assertSame('ASSIGNED', $g['worker_a']['outcome']);
        $this->assertSame('ASSIGNED', $g['worker_b']['outcome']);
        $this->assertGreaterThanOrEqual(1400, $g['worker_a']['duration_ms']);
        $this->assertLessThan(1400, $g['worker_b']['duration_ms']);

        $h = $result['scenarios']['different_properties'];
        $this->assertSame(1, $h['active_count_a']);
        $this->assertSame(1, $h['active_count_b']);
        $this->assertSame('ASSIGNED', $h['worker_a']['outcome']);
        $this->assertSame('ASSIGNED', $h['worker_b']['outcome']);
        $this->assertGreaterThanOrEqual(1400, $h['worker_a']['duration_ms']);
        $this->assertLessThan(1400, $h['worker_b']['duration_ms']);

        $i = $result['scenarios']['cross_property'];
        $this->assertSame(1, $i['active_count']);
        $this->assertSame(0, $i['sibling_property_assignment_count']);
        $this->assertEqualsCanonicalizing(['CONTROLLED_REJECTION', 'ASSIGNED'], [$i['worker_a']['outcome'], $i['worker_b']['outcome']]);

        $successfulWorkers = collect($result['scenarios'])->flatMap(fn (array $scenario): array => [$scenario['worker_a'], $scenario['worker_b']])->filter(fn (array $worker): bool => ! in_array($worker['outcome'], ['CONTROLLED_REJECTION', 'INTERNAL_FAILURE'], true));
        $this->assertTrue($successfulWorkers->every(fn (array $worker): bool => $worker['transaction_state'] === 'active' && $worker['lock_count'] > 0));
        $this->assertSame(0, $result['orphan_worker_count'] ?? -1);
        $this->assertTrue($result['db_dropped'] ?? false);

        $serialized = strtolower(json_encode($result, JSON_THROW_ON_ERROR));
        foreach (['db_pass', 'password', 'source_hash', 'idempotency_key', 'error_message', 'error_class', 'sqlstate', 'email', 'phone'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    /** @return array<string, mixed> */
    private function runCoordinator(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-p15-' . Str::lower(Str::random(10));
        mkdir($directory, 0700, true);
        $database = 'ivorq_concurrency_hk_p15_' . Str::lower(Str::random(8));
        $configFile = $directory . DIRECTORY_SEPARATOR . 'coordinator-config.json';
        $resultFile = $directory . DIRECTORY_SEPARATOR . 'coordinator-result.json';
        $pgsql = config('database.connections.pgsql');
        file_put_contents($configFile, json_encode(['db_name' => $database, 'barrier_dir' => $directory, 'base_path' => base_path(), 'db_host' => $pgsql['host'], 'db_port' => (string) $pgsql['port'], 'db_user' => $pgsql['username'], 'db_pass' => $pgsql['password'], 'result_file' => $resultFile], JSON_THROW_ON_ERROR));

        try {
            $script = __DIR__ . '/Support/P15DispatchAssignmentConcurrencyCoordinator.php';
            $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($configFile), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) return ['error_code' => 'COORDINATOR_START_FAILED'];
            foreach ($pipes as $pipe) fclose($pipe);
            $until = time() + 420;
            while (time() < $until && ! is_file($resultFile)) usleep(100000);
            if (! is_file($resultFile)) {
                proc_terminate($process);
                proc_close($process);
                return ['error_code' => 'COORDINATOR_TIMEOUT'];
            }
            $result = json_decode((string) file_get_contents($resultFile), true) ?: ['error_code' => 'RESULT_PARSE_FAILED'];
            proc_close($process);
            return $result;
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
            @rmdir($directory);
        }
    }
}

<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class GuestLedgerFolioAggregateConcurrencyProofTest extends PostgresTestCase
{
    public function test_folio_opening_is_concurrency_safe_in_isolated_postgresql_processes(): void
    {
        $runId = 'glfa' . strtolower(Str::random(6));
        $result = $this->runCoordinator($runId);

        $this->assertTrue($result['db_created'] ?? false, 'Disposable database must be created.');
        $this->assertTrue($result['migrations_ok'] ?? false, 'Disposable database migrations must succeed.');
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        // ── Same-key concurrency ─────────────────────────────────────────
        $sameKey = $result['same_key_concurrency'] ?? [];
        $this->assertTrue($sameKey['pid_different'] ?? false, 'Same-key workers must run in different PHP processes.');
        $this->assertTrue($sameKey['pg_different'] ?? false, 'Same-key workers must use different PG connections.');

        // Idempotency: same key must produce exactly ONE folio row
        $this->assertSame(1, $sameKey['folio_count'] ?? -1,
            'Same idempotency key must produce exactly one Folio row.');
        $this->assertSame(1, $sameKey['max_window'] ?? -1,
            'Same key must produce window 1 — no duplicate windows.');

        $workerA = $sameKey['worker_a'] ?? [];
        $workerB = $sameKey['worker_b'] ?? [];

        // Both workers must return the same folio identity
        if (($workerA['outcome'] ?? '') === 'FOLIO_OPENED' && ($workerB['outcome'] ?? '') === 'FOLIO_OPENED') {
            $this->assertSame($workerA['folio_id'], $workerB['folio_id'],
                'Same-key concurrent open must return the same folio.');
        }

        // ── Different-key concurrency ────────────────────────────────────
        $diffKey = $result['different_key_concurrency'] ?? [];
        $this->assertTrue($diffKey['pid_different'] ?? false, 'Different-key workers must run in different PHP processes.');
        $this->assertTrue($diffKey['pg_different'] ?? false, 'Different-key workers must use different PG connections.');

        // Different keys must produce TWO distinct folios
        $this->assertSame(2, $diffKey['folio_count'] ?? -1,
            'Different idempotency keys must produce two distinct Folios.');

        // Windows must be [1, 2] — distinct and ordered
        $windows = $diffKey['windows'] ?? [];
        $this->assertSame([1, 2], $windows,
            'Different keys must allocate distinct ordered window numbers.');

        $diffA = $diffKey['worker_a'] ?? [];
        $diffB = $diffKey['worker_b'] ?? [];

        if (($diffA['outcome'] ?? '') === 'FOLIO_OPENED' && ($diffB['outcome'] ?? '') === 'FOLIO_OPENED') {
            $this->assertNotSame($diffA['folio_id'], $diffB['folio_id'],
                'Different-key concurrent open must produce distinct folios.');
            $this->assertNotSame($diffA['folio_number'], $diffB['folio_number'],
                'Different-key concurrent open must produce distinct folio numbers.');
        }

        // ── Cleanup ──────────────────────────────────────────────────────
        $this->assertTrue($result['db_dropped'] ?? false,
            'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }

    private function runCoordinator(string $runId): array
    {
        $dbName = 'ivorq_concurrency_glf_a_' . $runId . '_' . strtolower(Str::random(4));
        $barrierDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-glfa-conc-' . $runId . '-' . Str::random(4);
        @mkdir($barrierDir, 0700, true);

        try {
            $coordinatorScript = __DIR__ . '/Support/GuestLedgerFolioAggregateConcurrencyCoordinator.php';
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

            $process = proc_open(
                PHP_BINARY . ' ' . escapeshellarg($coordinatorScript) . ' ' . escapeshellarg($configFile),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
                $pipes,
                base_path()
            );

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

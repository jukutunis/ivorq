<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class GuestLedgerFolioAggregateConcurrencyProofTest extends PostgresTestCase
{
    public function test_folio_opening_is_concurrency_safe_in_isolated_postgresql_processes(): void
    {
        $runId = 'glfc' . strtolower(Str::random(6));
        $result = $this->runCoordinator($runId);

        $this->assertTrue($result['db_created'] ?? false, 'Disposable database must be created.');
        $this->assertTrue($result['migrations_ok'] ?? false, 'Disposable database migrations must succeed.');
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        // ── Same key concurrency ──────────────────────────────────────────
        $sk = $result['same_key_concurrency'] ?? [];
        $this->assertTrue($sk['pid_different'] ?? false);
        $this->assertTrue($sk['pg_different'] ?? false);
        $this->assertSame(1, $sk['folio_count'] ?? -1, 'Same-key must produce exactly one Folio.');
        $this->assertSame(1, $sk['max_window'] ?? -1, 'Same-key must produce window 1.');

        // Both workers must report FOLIO_OPENED — no silent failures
        $this->assertSame('FOLIO_OPENED', $sk['worker_a']['outcome'] ?? '?',
            'Worker A must succeed with FOLIO_OPENED.');
        $this->assertSame('FOLIO_OPENED', $sk['worker_b']['outcome'] ?? '?',
            'Worker B must succeed with FOLIO_OPENED.');
        $this->assertSame($sk['worker_a']['folio_id'] ?? '', $sk['worker_b']['folio_id'] ?? 'x',
            'Same-key workers must return the same folio identity.');

        // ── Different key concurrency (same reservation) ──────────────────
        $dk = $result['different_key_concurrency'] ?? [];
        $this->assertTrue($dk['pid_different'] ?? false);
        $this->assertTrue($dk['pg_different'] ?? false);
        $this->assertSame(2, $dk['folio_count'] ?? -1, 'Different keys must produce two Folios.');
        $this->assertSame([1, 2], $dk['windows'] ?? [], 'Different keys must allocate windows [1, 2].');

        $this->assertSame('FOLIO_OPENED', $dk['worker_a']['outcome'] ?? '?');
        $this->assertSame('FOLIO_OPENED', $dk['worker_b']['outcome'] ?? '?');
        $this->assertNotSame($dk['worker_a']['folio_id'] ?? '', $dk['worker_b']['folio_id'] ?? '',
            'Different keys must produce distinct folio identities.');
        $this->assertNotSame($dk['worker_a']['folio_number'] ?? '', $dk['worker_b']['folio_number'] ?? '',
            'Different keys must produce distinct folio numbers.');

        // ── Cross-reservation concurrency (same property, different reservations) ─
        $cr = $result['cross_reservation_concurrency'] ?? [];
        $this->assertTrue($cr['pid_different'] ?? false);
        $this->assertTrue($cr['pg_different'] ?? false);
        $this->assertSame(2, $cr['folio_count'] ?? -1, 'Cross-reservation must produce two Folios.');
        $this->assertSame([1], $cr['windows_a'] ?? [], 'Each reservation gets window 1.');
        $this->assertSame([1], $cr['windows_b'] ?? [], 'Each reservation gets window 1.');

        $this->assertSame('FOLIO_OPENED', $cr['worker_a']['outcome'] ?? '?');
        $this->assertSame('FOLIO_OPENED', $cr['worker_b']['outcome'] ?? '?');
        $this->assertNotSame($cr['worker_a']['folio_number'] ?? '', $cr['worker_b']['folio_number'] ?? '',
            'Cross-reservation concurrent openings must produce distinct folio numbers.');

        // ── Cross-property concurrency ────────────────────────────────────
        $cp = $result['cross_property_concurrency'] ?? [];
        $this->assertTrue($cp['pid_different'] ?? false);
        $this->assertSame(2, $cp['total_folios'] ?? -1, 'Cross-property must produce two Folios.');
        // Different properties = independent number namespaces
        // Both can start from FOL-00001

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

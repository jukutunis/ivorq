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
        $this->assertTrue($result['migrations_ok'] ?? false, 'Migrations must succeed.');
        $this->assertSame('ivorq_testing', $result['protected_database'] ?? null);
        $this->assertNull($result['error'] ?? null, 'Coordinator error: ' . ($result['error'] ?? 'none'));

        // ── Same key ─────────────────────────────────────────────────────
        $sk = $result['same_key_concurrency'] ?? [];
        $this->assertTrue($sk['pid_different'] ?? false);
        $this->assertTrue($sk['pg_different'] ?? false);
        $this->assertSame(1, $sk['folio_count'] ?? -1);
        $this->assertSame(1, $sk['max_window'] ?? -1);
        $this->assertSame('FOLIO_OPENED', $sk['worker_a']['outcome'] ?? '?', 'Worker A outcome');
        $this->assertSame('FOLIO_OPENED', $sk['worker_b']['outcome'] ?? '?', 'Worker B outcome');
        $this->assertSame($sk['worker_a']['folio_id'] ?? '', $sk['worker_b']['folio_id'] ?? 'x');

        // ── Different keys (same reservation) ────────────────────────────
        $dk = $result['different_key_concurrency'] ?? [];
        $this->assertTrue($dk['pid_different'] ?? false);
        $this->assertTrue($dk['pg_different'] ?? false);
        $this->assertSame(2, $dk['folio_count'] ?? -1);
        $this->assertSame([1, 2], $dk['windows'] ?? []);
        $this->assertSame('FOLIO_OPENED', $dk['worker_a']['outcome'] ?? '?');
        $this->assertSame('FOLIO_OPENED', $dk['worker_b']['outcome'] ?? '?');
        $this->assertNotSame($dk['worker_a']['folio_id'] ?? '', $dk['worker_b']['folio_id'] ?? '');
        $this->assertNotSame($dk['worker_a']['folio_number'] ?? '', $dk['worker_b']['folio_number'] ?? '');

        // ── Cross-reservation ────────────────────────────────────────────
        $cr = $result['cross_reservation_concurrency'] ?? [];
        $this->assertTrue($cr['pid_different'] ?? false);
        $this->assertTrue($cr['pg_different'] ?? false);
        $this->assertSame(2, $cr['folio_count'] ?? -1);
        $this->assertSame([1], $cr['windows_a'] ?? []);
        $this->assertSame([1], $cr['windows_b'] ?? []);
        $this->assertSame('FOLIO_OPENED', $cr['worker_a']['outcome'] ?? '?');
        $this->assertSame('FOLIO_OPENED', $cr['worker_b']['outcome'] ?? '?');
        $this->assertNotSame($cr['worker_a']['folio_number'] ?? '', $cr['worker_b']['folio_number'] ?? '');

        // ── Cross-property ───────────────────────────────────────────────
        $cp = $result['cross_property_concurrency'] ?? [];
        $this->assertTrue($cp['pid_different'] ?? false);
        $this->assertTrue($cp['pg_different'] ?? false);
        $this->assertSame(2, $cp['total_folios'] ?? -1);

        $cpA = $cp['worker_a'] ?? [];
        $cpB = $cp['worker_b'] ?? [];
        $this->assertSame('FOLIO_OPENED', $cpA['outcome'] ?? '?', 'Cross-property worker A outcome');
        $this->assertSame('FOLIO_OPENED', $cpB['outcome'] ?? '?', 'Cross-property worker B outcome');
        $this->assertSame($cpA['property_id'] ?? '', $cp['property_id_a'] ?? '', 'Worker A folio belongs to property A');
        $this->assertSame($cpB['property_id'] ?? '', $cp['property_id_b'] ?? '', 'Worker B folio belongs to property B');
        // Independent property namespaces may legitimately produce the same
        // folio number — do not assert they differ. Instead, assert each is
        // independently valid (non-empty, correct window).
        $this->assertNotEmpty($cpA['folio_number'] ?? '', 'Property A folio number must be set');
        $this->assertNotEmpty($cpB['folio_number'] ?? '', 'Property B folio number must be set');
        $this->assertSame(1, $cpA['window_number'] ?? 0, 'Property A gets window 1');
        $this->assertSame(1, $cpB['window_number'] ?? 0, 'Property B gets window 1');

        // ── Post vs Void ─────────────────────────────────────────────────
        $pv = $result['post_vs_void_concurrency'] ?? [];
        $this->assertTrue($pv['pid_different'] ?? false, 'Post-vs-void workers must be different PHP processes.');
        $this->assertTrue($pv['pg_different'] ?? false, 'Post-vs-void workers must use different PG connections.');
        $this->assertSame('ITEM_POSTED', $pv['worker_a']['outcome'] ?? '?', 'Post worker must succeed.');
        $this->assertSame('ITEM_VOIDED', $pv['worker_b']['outcome'] ?? '?', 'Void worker must succeed.');

        // Neither worker should have an error
        $this->assertNull($pv['worker_a']['error'] ?? null, 'Post worker must have no error.');
        $this->assertNull($pv['worker_b']['error'] ?? null, 'Void worker must have no error.');

        // Active items: exactly 1 (the new charge; original was voided)
        $this->assertSame(1, $pv['active_item_count'] ?? -1, 'Exactly one active item after concurrent post+void.');

        // Original target item must be void
        $this->assertTrue($pv['original_item_is_void'] ?? false, 'Original item must be voided.');

        // New item must exist and be active
        $this->assertTrue($pv['new_item_exists'] ?? false, 'New concurrent item must exist.');
        $this->assertTrue($pv['new_item_is_active'] ?? false, 'New concurrent item must be active (not void).');

        // Cached totals must match fresh exact PostgreSQL recalculation
        $this->assertSame($pv['fresh_charges'] ?? 'x', $pv['final_charges'] ?? 'y',
            'Final cached total_charges must match fresh database recalculation.');
        $this->assertSame($pv['fresh_payments'] ?? 'x', $pv['final_payments'] ?? 'y',
            'Final cached total_payments must match fresh database recalculation.');
        $this->assertSame($pv['fresh_balance'] ?? 'x', $pv['final_balance'] ?? 'y',
            'Final cached balance must match fresh database recalculation.');

        // Folio must remain OPEN
        $this->assertSame('open', strtolower($pv['folio_status'] ?? 'unknown'), 'Folio must remain OPEN.');

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

<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GLF-A Migration up/down/up proof on a disposable PostgreSQL database.
 *
 * Proves every migration item individually:
 *  - UP: window/idempotency backfill, positive check, uniqueness, composite FK,
 *    index, property_id_id_unique, data preservation
 *  - DOWN: columns, constraints, composite FK, index (via pg_indexes),
 *    property_id_id_unique — removed; legacy data preserved
 *  - REAPPLY: all UP items re-verified after reapply
 */
class GuestLedgerFolioAggregateMigrationProofTest extends TestCase
{
    public function test_glf_a_migration_up_down_up_on_disposable_database(): void
    {
        $runId = 'glfm' . strtolower(Str::random(6));

        $scriptPath = __DIR__ . '/Support/GuestLedgerFolioAggregateMigrationProofRunner.php';
        $configFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-glfa-mig-config-' . $runId . '.json';
        $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-glfa-mig-result-' . $runId . '.json';

        $pgsql = config('database.connections.pgsql');
        $config = [
            'run_id' => $runId,
            'base_path' => base_path(),
            'db_host' => $pgsql['host'] ?? '127.0.0.1',
            'db_port' => (string) ($pgsql['port'] ?? '5432'),
            'db_user' => $pgsql['username'],
            'db_pass' => $pgsql['password'],
            'result_file' => $resultFile,
        ];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

        $process = proc_open(
            PHP_BINARY . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($configFile),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        if (! is_resource($process)) {
            $this->fail('FAILED_TO_START_MIGRATION_PROOF_RUNNER');
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

        foreach ([$configFile, $resultFile] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        $this->assertNull($result['error'] ?? null, 'Runner error: ' . ($result['error'] ?? 'none'));

        // ── Pre-GLF-A ─────────────────────────────────────────────────────
        $this->assertTrue($result['pre_migration_ok'] ?? false, 'Pre-GLF-A migration must succeed.');
        $this->assertSame(2, $result['legacy_folios_inserted'] ?? 0, 'Must insert 2 legacy folios.');
        $this->assertSame(2, $result['legacy_items_inserted'] ?? 0, 'Must insert 2 legacy items.');

        // ── UP ────────────────────────────────────────────────────────────
        $this->assertTrue($result['migrate_up_ok'] ?? false, 'GLF-A migration UP must succeed.');

        $this->assertTrue($result['up_window_backfill_ok'] ?? false, 'UP: window backfill must be deterministic.');
        $this->assertTrue($result['up_idempotency_backfill_ok'] ?? false, 'UP: idempotency backfill must be deterministic.');
        $this->assertTrue($result['up_positive_window_check_ok'] ?? false, 'UP: positive window check must reject zero.');
        $this->assertTrue($result['up_window_unique_ok'] ?? false, 'UP: window uniqueness must be enforced.');
        $this->assertTrue($result['up_idempotency_unique_ok'] ?? false, 'UP: idempotency uniqueness must be enforced.');
        $this->assertTrue($result['up_property_id_id_unique_exists'] ?? false, 'UP: property_id_id_unique must exist.');
        $this->assertTrue($result['up_reservation_window_index_exists'] ?? false, 'UP: reservation_window_index must exist.');
        $this->assertTrue($result['up_composite_fk_exists'] ?? false, 'UP: composite FK must exist in schema.');
        $this->assertTrue($result['up_composite_fk_enforced'] ?? false, 'UP: composite FK must be enforced.');
        $this->assertSame(2, $result['folio_count_after_up'] ?? 0, 'UP: folio count must be preserved.');
        $this->assertSame(2, $result['item_count_after_up'] ?? 0, 'UP: item count must be preserved.');

        // ── DOWN ──────────────────────────────────────────────────────────
        $this->assertTrue($result['migrate_down_ok'] ?? false, 'GLF-A migration DOWN must succeed.');

        $this->assertTrue($result['down_window_number_removed'] ?? false, 'DOWN: window_number must be removed.');
        $this->assertTrue($result['down_idempotency_key_removed'] ?? false, 'DOWN: opening_idempotency_key must be removed.');
        $this->assertTrue($result['down_positive_check_removed'] ?? false, 'DOWN: positive window check must be removed.');
        $this->assertTrue($result['down_window_unique_removed'] ?? false, 'DOWN: window unique constraint must be removed.');
        $this->assertTrue($result['down_idempotency_unique_removed'] ?? false, 'DOWN: idempotency unique constraint must be removed.');
        $this->assertTrue($result['down_property_id_id_unique_removed'] ?? false, 'DOWN: property_id_id_unique must be removed.');
        $this->assertTrue($result['down_composite_fk_removed'] ?? false, 'DOWN: composite FK must be removed.');
        $this->assertTrue($result['down_reservation_window_index_removed'] ?? false, 'DOWN: reservation_window_index must be removed (pg_indexes).');

        $this->assertTrue($result['down_legacy_folio_ids_preserved'] ?? false, 'DOWN: legacy folio IDs must be preserved.');
        $this->assertTrue($result['down_legacy_item_ids_preserved'] ?? false, 'DOWN: legacy item IDs must be preserved.');
        $this->assertTrue($result['down_folio_count_preserved'] ?? false, 'DOWN: folio count must be preserved.');
        $this->assertTrue($result['down_item_count_preserved'] ?? false, 'DOWN: item count must be preserved.');

        // ── REAPPLY ───────────────────────────────────────────────────────
        $this->assertTrue($result['migrate_reup_ok'] ?? false, 'GLF-A migration reapply must succeed.');

        $this->assertTrue($result['reup_window_backfill_ok'] ?? false, 'REUP: window backfill must be deterministic.');
        $this->assertTrue($result['reup_idempotency_backfill_ok'] ?? false, 'REUP: idempotency backfill must be deterministic.');
        $this->assertTrue($result['reup_positive_window_check_ok'] ?? false, 'REUP: positive window check must reject zero.');
        $this->assertTrue($result['reup_window_unique_ok'] ?? false, 'REUP: window uniqueness must be enforced.');
        $this->assertTrue($result['reup_idempotency_unique_ok'] ?? false, 'REUP: idempotency uniqueness must be enforced.');
        $this->assertTrue($result['reup_property_id_id_unique_exists'] ?? false, 'REUP: property_id_id_unique must exist.');
        $this->assertTrue($result['reup_reservation_window_index_exists'] ?? false, 'REUP: reservation_window_index must exist (pg_indexes).');
        $this->assertTrue($result['reup_composite_fk_exists'] ?? false, 'REUP: composite FK must exist in schema.');
        $this->assertTrue($result['reup_composite_fk_enforced'] ?? false, 'REUP: composite FK must be enforced.');

        $this->assertTrue($result['reup_legacy_folio_ids_preserved'] ?? false, 'REUP: legacy folio IDs must be preserved.');
        $this->assertTrue($result['reup_legacy_item_ids_preserved'] ?? false, 'REUP: legacy item IDs must be preserved.');
        $this->assertTrue($result['reup_folio_count_preserved'] ?? false, 'REUP: folio count must be preserved.');
        $this->assertTrue($result['reup_item_count_preserved'] ?? false, 'REUP: item count must be preserved.');

        // ── Cleanup ───────────────────────────────────────────────────────
        $this->assertTrue($result['db_dropped'] ?? false,
            'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }
}

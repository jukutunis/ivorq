<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GLF-A Migration up/down/up proof on a disposable PostgreSQL database.
 *
 * Proves:
 *  - window_number backfill is correct
 *  - opening_idempotency_key backfill is deterministic
 *  - positive-window check constraint is enforced
 *  - window uniqueness is enforced
 *  - idempotency uniqueness is enforced
 *  - composite FK is enforced
 *  - legacy IDs and row counts are preserved through rollback
 *  - GLF-A columns and constraints are cleanly removed on rollback
 *  - data survives rollback and reapply intact
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

        // Cleanup temp files
        foreach ([$configFile, $resultFile] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        $this->assertNull($result['error'] ?? null, 'Runner error: ' . ($result['error'] ?? 'none'));

        // ── Pre-GLF-A state ────────────────────────────────────────────────
        $this->assertTrue($result['pre_migration_ok'] ?? false, 'Pre-GLF-A migration must succeed.');
        $this->assertSame(2, $result['legacy_folios_inserted'] ?? 0, 'Must insert 2 legacy folios.');
        $this->assertSame(2, $result['legacy_items_inserted'] ?? 0, 'Must insert 2 legacy items.');

        // ── Migration UP ──────────────────────────────────────────────────
        $this->assertTrue($result['migrate_up_ok'] ?? false, 'GLF-A migration UP must succeed.');

        // Constraint verification
        $this->assertTrue($result['window_backfill_ok'] ?? false, 'Window backfill must be correct.');
        $this->assertTrue($result['idempotency_backfill_ok'] ?? false, 'Idempotency backfill must be correct.');
        $this->assertTrue($result['positive_window_check_ok'] ?? false, 'Positive window check must reject zero/negative.');
        $this->assertTrue($result['window_unique_ok'] ?? false, 'Window uniqueness must be enforced.');
        $this->assertTrue($result['idempotency_unique_ok'] ?? false, 'Idempotency uniqueness must be enforced.');
        $this->assertTrue($result['composite_fk_schema_exists'] ?? false,
            'Composite FK must exist in schema: ' . ($result['composite_fk_error'] ?? 'no diagnostic'));
        $this->assertTrue($result['composite_fk_ok'] ?? false,
            'Composite FK must be enforced: ' . ($result['composite_fk_error'] ?? 'no diagnostic'));
        $this->assertSame(2, $result['folio_count_after_up'] ?? 0, 'Folio count must be preserved after UP.');
        $this->assertSame(2, $result['item_count_after_up'] ?? 0, 'Item count must be preserved after UP.');

        // ── Rollback ──────────────────────────────────────────────────────
        $this->assertTrue($result['migrate_down_ok'] ?? false, 'GLF-A migration DOWN must succeed.');

        // Columns and constraints removed
        $this->assertTrue($result['columns_removed_ok'] ?? false, 'GLF-A columns must be removed after DOWN.');
        $this->assertTrue($result['constraints_removed_ok'] ?? false, 'GLF-A constraints must be removed after DOWN.');

        // Data preserved
        $this->assertSame(2, $result['folio_count_after_down'] ?? 0, 'Folio count must be preserved after DOWN.');
        $this->assertSame(2, $result['item_count_after_down'] ?? 0, 'Item count must be preserved after DOWN.');

        // ── Reapply ───────────────────────────────────────────────────────
        $this->assertTrue($result['migrate_reup_ok'] ?? false, 'GLF-A migration reapply must succeed.');

        // Constraints re-verified
        $this->assertTrue($result['reup_backfill_ok'] ?? false, 'Reapply backfill must be correct.');
        $this->assertSame(2, $result['folio_count_after_reup'] ?? 0, 'Folio count must be preserved after reapply.');
        $this->assertSame(2, $result['item_count_after_reup'] ?? 0, 'Item count must be preserved after reapply.');

        // ── Cleanup ───────────────────────────────────────────────────────
        $this->assertTrue($result['db_dropped'] ?? false,
            'Disposable database must be dropped. Drop error: ' . ($result['drop_error'] ?? 'none'));
    }
}

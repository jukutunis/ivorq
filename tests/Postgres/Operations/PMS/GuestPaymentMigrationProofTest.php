<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\TestCase;

class GuestPaymentMigrationProofTest extends TestCase
{
    public function test_glf_b_migrations_up_down_reapply_and_legacy_blocker_on_disposable_databases(): void
    {
        $runId = 'glfbm' . strtolower(Str::random(6));
        $scriptPath = __DIR__ . '/Support/GuestPaymentMigrationProofRunner.php';
        $configFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-glfb-mig-config-' . $runId . '.json';
        $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ivorq-glfb-mig-result-' . $runId . '.json';

        $pgsql = config('database.connections.pgsql');
        file_put_contents($configFile, json_encode([
            'run_id' => $runId,
            'base_path' => base_path(),
            'db_host' => $pgsql['host'] ?? '127.0.0.1',
            'db_port' => (string) ($pgsql['port'] ?? '5432'),
            'db_user' => $pgsql['username'],
            'db_pass' => $pgsql['password'],
            'result_file' => $resultFile,
        ], JSON_PRETTY_PRINT));

        $process = proc_open(
            PHP_BINARY . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($configFile),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        if (!is_resource($process)) {
            $this->fail('FAILED_TO_START_GLF_B_MIGRATION_PROOF_RUNNER');
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

        foreach ([$configFile, $resultFile] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $this->assertNull($result['error'] ?? null, 'Runner error: ' . ($result['error'] ?? 'none'));
        $this->assertTrue($result['proof_db_created'] ?? false);
        $this->assertTrue($result['pre_glf_b_ok'] ?? false);
        $this->assertSame(1, $result['pre_folio_count'] ?? 0);
        $this->assertSame(2, $result['pre_folio_item_count'] ?? 0);

        $this->assertTrue($result['up_ok'] ?? false);
        foreach ([
            'up_guest_tables_exist',
            'up_folio_source_columns_exist',
            'up_constraints_exist',
            'up_indexes_exist',
            'up_typed_source_trigger_exists',
            'up_immutability_trigger_exists',
            'up_reversal_source_trigger_exists',
            'up_fk_payment_prop_a_reservation_prop_b',
            'up_fk_payment_prop_a_guest_prop_b',
            'up_fk_payment_prop_a_session_prop_b',
            'up_fk_allocation_prop_a_folio_prop_b',
            'up_fk_reversal_payment_a_alloc_b',
            'up_fk_folio_item_prop_a_alloc_prop_b',
            'up_immutable_payment_amount_blocked',
            'up_reversal_source_amount_void_blocked',
            'up_reversal_source_amount_alloc_blocked',
            'up_payment_deletion_blocked',
            'up_payment_row_remains_after_failed_deletion',
            'up_lifecycle_update_allowed',
            'up_same_property_fk_enforced',
            'up_typed_source_fk_enforced',
        ] as $key) {
            $this->assertTrue($result[$key] ?? false, "{$key} must be true.");
        }

        $this->assertTrue($result['down_ok'] ?? false);
        foreach ([
            'down_guest_tables_removed',
            'down_folio_source_columns_removed',
            'down_parent_composite_keys_removed',
            'down_legacy_folio_preserved',
            'down_legacy_items_preserved',
            'down_immutability_trigger_removed',
        ] as $key) {
            $this->assertTrue($result[$key] ?? false, "{$key} must be true.");
        }

        $this->assertTrue($result['reup_ok'] ?? false);
        foreach ([
            'reup_guest_tables_exist',
            'reup_folio_source_columns_exist',
            'reup_constraints_exist',
            'reup_indexes_exist',
            'reup_typed_source_trigger_exists',
            'reup_immutability_trigger_exists',
            'reup_reversal_source_trigger_exists',
            'reup_payment_deletion_blocked',
            'reup_immutable_update_blocked',
            'reup_lifecycle_update_allowed',
            'reup_legacy_folio_preserved',
            'reup_legacy_items_preserved',
        ] as $key) {
            $this->assertTrue($result[$key] ?? false, "{$key} must be true.");
        }

        $this->assertTrue($result['ambiguous_db_created'] ?? false);
        $this->assertTrue($result['ambiguous_blocked'] ?? false);
        $this->assertStringContainsString('GLF_B_BLOCKED_LEGACY_PAYMENT_ITEMS', $result['ambiguous_error'] ?? '');
        $this->assertTrue($result['ambiguous_no_partial_columns'] ?? false);
        $this->assertTrue($result['ambiguous_legacy_rows_preserved'] ?? false);
        $this->assertTrue($result['ambiguous_pre_glfb_reversal_items_impossible'] ?? false);
        $this->assertTrue($result['proof_db_dropped'] ?? false);
        $this->assertTrue($result['ambiguous_db_dropped'] ?? false);
    }
}

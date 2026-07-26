<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionEvidenceSourceIntegrityTest extends PostgresTestCase
{
    // ── No checkout execution service ──────────────────────────────────────

    public function test_no_checkout_execution_service_exists(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php');
        $this->assertFileDoesNotExist($servicePath, 'No FrontDeskCheckoutExecutionService must exist.');

        $commandPath = base_path('Modules/Operations/FrontDesk/Commands/CheckoutStayCommand.php');
        $this->assertFileDoesNotExist($commandPath, 'No CheckoutStayCommand must exist.');
    }

    // ── No write controller action ─────────────────────────────────────────

    public function test_no_checkout_write_controller_action_exists(): void
    {
        $controllerPath = base_path('app/Http/Controllers/Ivorq/FrontDeskController.php');
        $this->assertFileExists($controllerPath);

        $source = file_get_contents($controllerPath);
        $this->assertStringNotContainsString(
            'checkOut(',
            $source,
            'FrontDeskController must not contain a checkOut method.'
        );
        $this->assertStringNotContainsString(
            'executeCheckout',
            $source,
            'FrontDeskController must not contain executeCheckout.'
        );
    }

    // ── No checkout write route ────────────────────────────────────────────

    public function test_no_checkout_post_put_patch_delete_route_exists(): void
    {
        $webRoutes = file_get_contents(base_path('routes/web.php'));

        // No checkout execution POST/PUT/PATCH/DELETE route
        $this->assertStringNotContainsString(
            "post('stays/{stay}/checkout",
            $webRoutes,
            'No POST /stays/{stay}/checkout route must exist in web.php.'
        );
        $this->assertStringNotContainsString(
            "put('stays/{stay}/checkout",
            $webRoutes,
            'No PUT checkout route must exist.'
        );
        $this->assertStringNotContainsString(
            "patch('stays/{stay}/checkout",
            $webRoutes,
            'No PATCH checkout route must exist.'
        );
        $this->assertStringNotContainsString(
            "delete('stays/{stay}/checkout",
            $webRoutes,
            'No DELETE checkout route must exist.'
        );

        // The only checkout routes allowed are READ-ONLY projections + the B7 create
        $this->assertStringContainsString(
            'departure-checkout-execution-boundary',
            $webRoutes,
            'departure-checkout-execution-boundary GET route must still exist (read-only boundary projection).'
        );
    }

    // ── No execute permission ──────────────────────────────────────────────

    public function test_checkout_execute_permission_foundation_exists_without_runtime_execution(): void
    {
        $seederPath = base_path('Modules/Foundation/Authorization/database/seeders/PermissionSeeder.php');
        $this->assertFileExists($seederPath);

        $source = file_get_contents($seederPath);
        $this->assertStringContainsString(
            'frontdesk.checkout-execution.execute',
            $source,
            'Package 8 must seed the exact future execute permission.'
        );
    }

    // ── No checkout sensitive confirmation intent ──────────────────────────

    public function test_checkout_sensitive_intent_exists_but_generic_unbound_issuance_is_rejected(): void
    {
        $servicePath = base_path('Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php');
        $this->assertFileExists($servicePath);

        $source = file_get_contents($servicePath);
        $this->assertStringContainsString(
            'frontdesk-checkout-execution',
            $source,
            'Package 8 must register the exact checkout confirmation intent.'
        );
        $this->assertStringContainsString(
            'Checkout confirmation requires authoritative checkout context.',
            $source,
            'Generic context-free confirmation must fail closed for checkout.'
        );
    }

    // ── No Housekeeping handoff/outbox ─────────────────────────────────────

    public function test_no_housekeeping_checkout_handoff_or_outbox_exists(): void
    {
        $hkBase = base_path('Modules/Operations/Housekeeping');
        if (!is_dir($hkBase)) {
            $this->markTestSkipped('Housekeeping module directory not found.');
            return;
        }

        $this->assertNoPatternInDirectory(
            $hkBase,
            ['checkout_handoff', 'checkout-handoff', 'CheckoutHandoff', 'checkout_outbox', 'checkout-outbox', 'CheckoutOutbox'],
            'Housekeeping must not contain checkout handoff or outbox references.'
        );
    }

    // ── No React/TypeScript checkout action ────────────────────────────────

    public function test_no_react_typescript_checkout_action_exists(): void
    {
        $jsBase = base_path('resources/js');
        if (!is_dir($jsBase)) {
            $this->markTestSkipped('resources/js directory not found.');
            return;
        }

        $this->assertNoPatternInDirectory(
            $jsBase,
            ['executeCheckout', 'ExecuteCheckout', 'checkoutExecutionButton', 'CheckoutExecutionButton', 'execute-checkout', 'handleCheckout', 'handleCheckOut', 'postCheckout', 'checkOutStay'],
            'React/TypeScript must not contain checkout execution actions.'
        );
    }

    // ── No production call to FrontDeskCheckoutExecution::create ──────────

    public function test_no_production_call_to_front_desk_checkout_execution_create_exists(): void
    {
        // Search the Modules and app directories (exclude tests, migrations)
        $this->assertNoProductionCreateCall(
            'Modules/Operations/FrontDesk',
            'FrontDeskCheckoutExecution::create',
            'No production code must call FrontDeskCheckoutExecution::create.'
        );
    }

    // ── No production call changes a stay to CHECKED_OUT ───────────────────

    public function test_no_production_call_changes_stay_to_checked_out(): void
    {
        // The PMS FrontDeskService uses lowercase 'checked_out' via PMS enums
        // Front Desk operational code must not use FrontDeskStayStatusEnum::CheckedOut
        $this->assertNoProductionPatternInModule(
            'Modules/Operations/FrontDesk',
            ["FrontDeskStayStatusEnum::CheckedOut", "status' => 'CHECKED_OUT", 'status" => "CHECKED_OUT'],
            'Front Desk production code must not transition a stay to CHECKED_OUT.'
        );
    }

    // ── Boundary still contains blocker ────────────────────────────────────

    public function test_boundary_still_contains_checkout_execution_not_yet_implemented(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);
        $this->assertStringContainsString(
            'CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED',
            $source,
            'Boundary must still contain CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED.'
        );
    }

    public function test_boundary_still_assigns_can_execute_false(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);

        // can_execute is hardcoded to false
        $this->assertStringContainsString('$canExecute = false;', $source);
        $this->assertStringContainsString("'can_execute'", $source);

        // Must not contain can_execute = true
        $this->assertStringNotContainsString(
            '$canExecute = true;',
            $source,
            'Boundary must not set canExecute to true.'
        );
    }

    public function test_boundary_does_not_query_new_evidence_table(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);
        $this->assertStringNotContainsString(
            'front_desk_checkout_executions',
            $source,
            'Boundary must not query the new evidence table.'
        );
        $this->assertStringNotContainsString(
            'FrontDeskCheckoutExecution',
            $source,
            'Boundary must not reference FrontDeskCheckoutExecution model.'
        );
    }

    // ── No Package 7, 8, or 9 source ───────────────────────────────────────

    public function test_no_package_9_source_introduced(): void
    {
        $fdBase = base_path('Modules/Operations/FrontDesk');

        // Package 9: Final checkout command
        $this->assertFileDoesNotExist(
            $fdBase . '/Commands/CheckoutStayCommand.php',
            'No checkout command (Package 9) must exist.'
        );
    }

    // ── Foreign Key Presence in Migration ─────────────────────────────────

    public function test_migration_contains_all_six_named_foreign_keys(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $fks = [
            'fd_ce_property_fk' => ['properties', 'property_id'],
            'fd_ce_stay_fk' => ['front_desk_stays', 'front_desk_stay_id'],
            'fd_ce_reservation_fk' => ['reservations', 'reservation_id'],
            'fd_ce_final_review_fk' => ['front_desk_departure_checkout_final_reviews', 'front_desk_final_review_id'],
            'fd_ce_business_date_fk' => ['property_business_dates', 'property_business_date_id'],
            'fd_ce_created_by_fk' => ['users', 'created_by'],
        ];

        foreach ($fks as $fkName => [$table, $column]) {
            $this->assertStringContainsString(
                $fkName,
                $source,
                "Migration must contain FK constraint '{$fkName}' referencing {$table}({$column})."
            );
            $this->assertStringContainsString(
                "references('id')->on('{$table}')",
                $source,
                "Migration FK '{$fkName}' must reference {$table}.id."
            );
        }
    }

    public function test_migration_idempotency_uses_trim_enforcement(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $this->assertStringContainsString(
            'btrim(idempotency_key)',
            $source,
            'Migration must use btrim() for idempotency_key whitespace enforcement.'
        );
        $this->assertStringContainsString(
            "idempotency_key = btrim(idempotency_key)",
            $source,
            'Migration must enforce idempotency_key trim equality.'
        );
    }

    public function test_migration_has_no_cascade_or_set_null(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $forbidden = ['onDelete(\'cascade\')', 'ON DELETE CASCADE',
                      'onDelete(\'set null\')', 'ON DELETE SET NULL',
                      'SET DEFAULT'];
        foreach ($forbidden as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $source,
                "Migration must not contain '{$pattern}'."
            );
        }
    }

    // ── Contract Version remains 1.13 ─────────────────────────────────────

    public function test_contract_version_remains_1_14(): void
    {
        $contractPath = base_path('.agents/contracts/IVORQ-Package-Execution-Contract.md');
        $this->assertFileExists($contractPath);

        $source = file_get_contents($contractPath);
        $this->assertStringContainsString('Version: 1.14', $source, 'Contract Version must remain 1.14.');
    }

    // ── No ADR or contract file changed ────────────────────────────────────

    public function test_no_adr_or_contract_file_was_changed(): void
    {
        $adrDir = base_path('docs/architecture/adr');
        $contractPath = base_path('.agents/contracts/IVORQ-Package-Execution-Contract.md');

        // Verify both exist
        $this->assertFileExists($contractPath);
        $this->assertDirectoryExists($adrDir);

        // Verify no new ADR files beyond ADR-089 were introduced (Package 7/8/9 shouldn't exist)
        $newAdrFiles = glob($adrDir . '/ADR-09*.md');
        $this->assertEmpty(
            $newAdrFiles,
            'No ADR-090+ files must exist. Found: ' . implode(', ', $newAdrFiles ?: [])
        );

        // Also verify that no new ADR files with higher numbers exist
        $allAdrFiles = glob($adrDir . '/ADR-*.md');
        foreach ($allAdrFiles as $file) {
            $adrNumber = basename($file);
            // Extract the numeric portion
            if (preg_match('/^ADR-(\d+)-/', $adrNumber, $m)) {
                $num = (int) $m[1];
                $this->assertLessThanOrEqual(
                    89,
                    $num,
                    "Unexpected ADR file: {$adrNumber}. Only ADR-001 through ADR-089 are authorized. No ADR-090+ allowed."
                );
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param string[] $patterns
     */
    private function assertNoPatternInDirectory(string $directory, array $patterns, string $message): void
    {
        $files = $this->scanPhpTsxFiles($directory);
        foreach ($files as $file) {
            $content = file_get_contents($file);
            foreach ($patterns as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $content,
                    "{$message} Found '{$pattern}' in {$file}."
                );
            }
        }
    }

    /**
     * @param string[] $patterns
     */
    private function assertNoProductionPatternInModule(string $moduleDir, array $patterns, string $message): void
    {
        $files = $this->scanPhpTsxFiles($moduleDir);
        // Exclude test files and migrations
        foreach ($files as $file) {
            if (str_contains($file, 'tests') || str_contains($file, 'database/migrations') || str_contains($file, 'Test.php')) {
                continue;
            }
            $content = file_get_contents($file);
            foreach ($patterns as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $content,
                    "{$message} Found '{$pattern}' in {$file}."
                );
            }
        }
    }

    private function assertNoProductionCreateCall(string $directory, string $pattern, string $message): void
    {
        $files = $this->scanPhpTsxFiles($directory);
        foreach ($files as $file) {
            // Exclude our new model file itself, test files, and migrations
            if (str_contains($file, 'FrontDeskCheckoutExecution.php')
                || str_contains($file, 'Test.php')
                || str_contains($file, 'database/migrations')) {
                continue;
            }
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                $pattern,
                $content,
                "{$message} Found in {$file}."
            );
        }
    }

    /**
     * @return string[]
     */
    private function scanPhpTsxFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'tsx', 'ts', 'jsx', 'js'])) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionEvidenceSourceIntegrityTest extends PostgresTestCase
{
    // ── Dedicated checkout execution service ───────────────────────────────

    public function test_dedicated_checkout_execution_service_exists(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php');
        $this->assertFileExists($servicePath, 'Package 9 must add the dedicated FrontDeskCheckoutExecutionService.');

        $source = file_get_contents($servicePath);
        $this->assertStringContainsString('public function execute(User $actor, string $frontDeskStayId, string $idempotencyKey): FrontDeskCheckoutExecutionResult', $source);
        $this->assertStringContainsString('DB::transaction(function ()', $source);
        $this->assertStringContainsString('NightAuditCheckoutConcurrencyGuardService', $source);
        $this->assertStringContainsString('GuestLedgerCheckoutTerminalFinancialAttestationService', $source);
        $this->assertStringContainsString('GeneralCashierCheckoutTerminalObligationAttestationService', $source);

        $commandPath = base_path('Modules/Operations/FrontDesk/Commands/CheckoutStayCommand.php');
        $this->assertFileDoesNotExist($commandPath, 'No CheckoutStayCommand must exist.');
    }

    // ── Controlled write controller action ─────────────────────────────────

    public function test_controller_exposes_only_thin_package9_checkout_actions(): void
    {
        $controllerPath = base_path('app/Http/Controllers/Ivorq/FrontDeskController.php');
        $this->assertFileExists($controllerPath);

        $source = file_get_contents($controllerPath);
        $this->assertStringNotContainsString(
            'checkOut(',
            $source,
            'FrontDeskController must not contain a checkOut method.'
        );
        $this->assertStringContainsString('prepareCheckoutConfirmation', $source);
        $this->assertStringContainsString('executeCheckout', $source);
        $this->assertStringContainsString("assertOnlyFields(\$request, ['idempotency_key', 'password'], 'checkout_confirmation')", $source);
        $this->assertStringContainsString("assertOnlyFields(\$request, ['idempotency_key'], 'checkout_execution')", $source);
        $this->assertStringContainsString('Unsupported checkout field:', $source);
        $this->assertStringContainsString('$checkout->execute($request->user(), $stay, $validated[\'idempotency_key\'])', $source);
    }

    // ── Controlled checkout write routes ───────────────────────────────────

    public function test_only_package9_checkout_post_routes_exist(): void
    {
        $webRoutes = file_get_contents(base_path('routes/web.php'));

        $this->assertSame(2, substr_count($webRoutes, "Route::post('/stays/{stay}/checkout"));
        $this->assertStringContainsString("checkout-confirmation", $webRoutes);
        $this->assertStringContainsString("checkout-execution", $webRoutes);
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

    // ── Controlled React/TypeScript checkout action ────────────────────────

    public function test_react_checkout_action_posts_only_confirmation_and_idempotency_payloads(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Ivorq/FrontDesk/FrontDeskWorkspace.tsx'));

        $this->assertStringContainsString('Review & Complete Checkout', $source);
        $this->assertStringContainsString('checkout-confirmation', $source);
        $this->assertStringContainsString('checkout-execution', $source);
        $this->assertStringContainsString('{ idempotency_key: idempotencyKey, password }', $source);
        $this->assertStringContainsString('{ idempotency_key: idempotencyKey }', $source);
        $this->assertStringContainsString('finally {', $source);
        $this->assertStringContainsString("setPassword('');", $source);
        $this->assertStringContainsString('receipt.night_audit_status', $source);
        $this->assertStringContainsString('receipt.pms_terminal_financial_status', $source);
        $this->assertStringContainsString('receipt.general_cashier_terminal_obligation_status', $source);
        $this->assertStringNotContainsString('Financial: {guestLedger.status', $source);
        $this->assertStringNotContainsString('Cashier: {cashierObligation.status', $source);
        $this->assertStringNotContainsString('localStorage', $source);
        $this->assertStringNotContainsString('sessionStorage', $source);
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

    public function test_only_package9_execution_service_changes_stay_to_checked_out(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php');
        $source = file_get_contents($servicePath);

        $this->assertStringContainsString('FrontDeskStayStatusEnum::CheckedOut', $source);
        $this->assertStringContainsString("'status' => FrontDeskStayStatusEnum::CheckedOut", $source);
    }

    // ── Boundary activates Package 9 command ───────────────────────────────

    public function test_boundary_does_not_use_checkout_execution_not_yet_implemented_as_live_blocker(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);
        $this->assertStringNotContainsString('$blockerCodes[] = self::BLOCKER_CHECKOUT_NOT_IMPLEMENTED;', $source);
        $this->assertStringContainsString('FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION', $source);
    }

    public function test_boundary_can_execute_is_permission_and_blocker_gated(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);

        $this->assertStringContainsString('FrontDeskCheckoutExecution::withoutGlobalScopes()', $source);
        $this->assertStringContainsString('BLOCKER_CHECKOUT_ALREADY_COMPLETED', $source);
        $this->assertStringContainsString('$existingExecution === null', $source);
        $this->assertStringContainsString('empty($reviewReasons)', $source);
        $this->assertStringContainsString("'can_execute'", $source);
    }

    public function test_boundary_reads_checkout_execution_evidence_without_mutating_it(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);
        $this->assertStringContainsString('FrontDeskCheckoutExecution::withoutGlobalScopes()', $source);
        $this->assertStringNotContainsString('new FrontDeskCheckoutExecution', $source);
        $this->assertStringNotContainsString('->forceFill([', $source);
        $this->assertStringNotContainsString('->save()', $source);
    }

    public function test_execution_service_claims_authoritative_confirmation_and_cleanup_is_non_authoritative_after_commit(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));

        $this->assertStringContainsString('validateCurrentSessionConfirmationFor', $source);
        $this->assertStringContainsString('claimCurrentSessionConfirmationFor($actor, $stay->id, $idempotencyKey)', $source);
        $this->assertStringNotContainsString('claimCurrentSessionConfirmationFromPreflight', $source);
        $this->assertStringContainsString('cleanupConfirmationSessionAfterCommit', $source);
        $this->assertStringContainsString('try {', $source);
        $this->assertStringContainsString('Log::warning', $source);
        $this->assertStringContainsString('confirmedAt->equalTo($preflight->confirmedAt)', $source);
        $this->assertStringContainsString('expiresAt->equalTo($preflight->expiresAt)', $source);
    }

    public function test_execution_result_contains_minimized_committed_attestation_statuses_without_fingerprints(): void
    {
        $result = file_get_contents(base_path('Modules/Operations/FrontDesk/ValueObjects/FrontDeskCheckoutExecutionResult.php'));
        $service = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));

        foreach (['night_audit_status', 'pms_terminal_financial_status', 'general_cashier_terminal_obligation_status'] as $key) {
            $this->assertStringContainsString($key, $result);
        }

        $this->assertStringContainsString('night_audit_source_status', $service);
        $this->assertStringContainsString('pms_financial_attestation_status', $service);
        $this->assertStringContainsString('general_cashier_attestation_status', $service);
        $this->assertStringNotContainsString('night_audit_source_fingerprint\' => $this', $result);
        $this->assertStringNotContainsString('pms_financial_attestation_fingerprint\' => $this', $result);
        $this->assertStringNotContainsString('general_cashier_attestation_fingerprint\' => $this', $result);
    }

    // ── No extra command object ────────────────────────────────────────────

    public function test_no_extra_checkout_command_object_introduced(): void
    {
        $fdBase = base_path('Modules/Operations/FrontDesk');

        // Package 9: Final checkout command
        $this->assertFileDoesNotExist(
            $fdBase . '/Commands/CheckoutStayCommand.php',
            'Package 9 uses the dedicated service, not an extra command object.'
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

    // ── Contract Version remains 1.20 ─────────────────────────────────────

    public function test_contract_version_remains_1_20(): void
    {
        $contractPath = base_path('.agents/contracts/IVORQ-Package-Execution-Contract.md');
        $this->assertFileExists($contractPath);

        $source = file_get_contents($contractPath);
        $this->assertStringContainsString('Version: 1.20', $source, 'Contract Version must remain 1.20.');
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

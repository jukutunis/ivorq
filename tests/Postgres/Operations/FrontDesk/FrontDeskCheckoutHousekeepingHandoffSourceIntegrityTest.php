<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutHousekeepingHandoffSourceIntegrityTest extends PostgresTestCase
{
    // ── Frozen names confirmed absent ──────────────────────────────────────

    public function test_no_production_handoff_creation_service_exists(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffCreationService.php');
        $this->assertFileDoesNotExist($servicePath, 'No FrontDeskCheckoutHousekeepingHandoffCreationService must exist.');

        $servicePath2 = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffService.php');
        $this->assertFileDoesNotExist($servicePath2, 'No FrontDeskCheckoutHousekeepingHandoffService must exist.');
    }

    // ── No production FrontDeskCheckoutExecution::create path ──────────────

    public function test_no_production_call_to_front_desk_checkout_execution_create_exists(): void
    {
        $this->assertNoProductionCreateCall(
            'Modules/Operations/FrontDesk',
            'FrontDeskCheckoutExecution::create',
            'No production code must call FrontDeskCheckoutExecution::create.'
        );
    }

    // ── No stay CHECKED_OUT transition ────────────────────────────────────

    public function test_no_production_call_changes_stay_to_checked_out(): void
    {
        $this->assertNoProductionPatternInModule(
            'Modules/Operations/FrontDesk',
            ["FrontDeskStayStatusEnum::CheckedOut", "status' => 'CHECKED_OUT", 'status" => "CHECKED_OUT"'],
            'Front Desk production code must not transition a stay to CHECKED_OUT.'
        );
    }

    // ── No checkout command ───────────────────────────────────────────────

    public function test_no_checkout_command_exists(): void
    {
        $commandPath = base_path('Modules/Operations/FrontDesk/Commands/CheckoutStayCommand.php');
        $this->assertFileDoesNotExist($commandPath, 'No CheckoutStayCommand must exist.');

        $commandPath2 = base_path('app/Console/Commands/CheckoutStayCommand.php');
        $this->assertFileDoesNotExist($commandPath2, 'No app-level CheckoutStayCommand must exist.');
    }

    // ── No checkout orchestration service ─────────────────────────────────

    public function test_no_checkout_orchestration_service_exists(): void
    {
        $orchestrationPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutOrchestrationService.php');
        $this->assertFileDoesNotExist($orchestrationPath, 'No FrontDeskCheckoutOrchestrationService must exist.');
    }

    // ── No write route ────────────────────────────────────────────────────

    public function test_no_checkout_write_route_exists(): void
    {
        $webRoutes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsString(
            "post('stays/{stay}/checkout",
            $webRoutes,
            'No POST checkout route must exist.'
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

        // The read-only boundary routes must still exist
        $this->assertStringContainsString(
            'departure-checkout-execution-boundary',
            $webRoutes,
            'departure-checkout-execution-boundary GET route must still exist.'
        );
    }

    // ── No controller ─────────────────────────────────────────────────────

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

    // ── No execute permission ─────────────────────────────────────────────

    public function test_checkout_execute_permission_foundation_exists_without_runtime_execution(): void
    {
        $seederPath = base_path('Modules/Foundation/Authorization/database/seeders/PermissionSeeder.php');
        $this->assertFileExists($seederPath);

        $source = file_get_contents($seederPath);
        $this->assertStringContainsString(
            'frontdesk.checkout-execution.execute',
            $source,
            'Package 8 must register frontdesk.checkout-execution.execute permission foundation.'
        );
    }

    // ── No confirmation intent ────────────────────────────────────────────

    public function test_checkout_sensitive_intent_foundation_exists_but_generic_confirmation_fails_closed(): void
    {
        $servicePath = base_path('Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php');
        $this->assertFileExists($servicePath);

        $source = file_get_contents($servicePath);
        $this->assertStringContainsString(
            'frontdesk-checkout-execution',
            $source,
            'Package 8 must register frontdesk-checkout-execution intent foundation.'
        );
        $this->assertStringContainsString(
            'Checkout confirmation requires authoritative checkout context.',
            $source,
            'Generic sensitive confirmation must fail closed for checkout execution.'
        );
    }

    // ── No Housekeeping worker ────────────────────────────────────────────

    public function test_no_housekeeping_worker_exists(): void
    {
        $hkBase = base_path('Modules/Operations/Housekeeping');
        if (! is_dir($hkBase)) {
            $this->markTestSkipped('Housekeeping module directory not found.');
            return;
        }

        $this->assertFileDoesNotExist(
            $hkBase . '/Jobs/ProcessCheckoutHandoff.php',
            'No Housekeeping ProcessCheckoutHandoff job must exist.'
        );
        $this->assertFileDoesNotExist(
            $hkBase . '/Jobs/ConsumeCheckoutHandoff.php',
            'No Housekeeping ConsumeCheckoutHandoff job must exist.'
        );
    }

    // ── No queue job ──────────────────────────────────────────────────────

    public function test_no_checkout_queue_job_exists(): void
    {
        $frontDeskJobs = base_path('Modules/Operations/FrontDesk/Jobs');
        if (is_dir($frontDeskJobs)) {
            $files = glob($frontDeskJobs . '/*.php');
            foreach ($files as $file) {
                $basename = basename($file);
                $this->assertStringNotContainsString(
                    'Handoff',
                    $basename,
                    "No Handoff job file must exist in Front Desk Jobs: {$basename}"
                );
            }
        }
        $this->addToAssertionCount(1); // at least checked
    }

    // ── No event listener ─────────────────────────────────────────────────

    public function test_no_checkout_event_listener_exists(): void
    {
        $listenersPath = base_path('Modules/Operations/FrontDesk/Listeners');
        if (is_dir($listenersPath)) {
            $files = glob($listenersPath . '/*.php');
            foreach ($files as $file) {
                $basename = basename($file);
                $this->assertStringNotContainsString(
                    'Handoff',
                    $basename,
                    "No Handoff listener must exist: {$basename}"
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    // ── No Housekeeping readiness mutation ────────────────────────────────

    public function test_no_housekeeping_readiness_mutation_in_handoff_code(): void
    {
        $handoffModelPath = base_path('Modules/Operations/FrontDesk/Models/FrontDeskCheckoutHousekeepingHandoff.php');
        $this->assertFileExists($handoffModelPath);
        $source = file_get_contents($handoffModelPath);

        $this->assertStringNotContainsString(
            'HousekeepingRoomReadiness',
            $source,
            'Handoff model must not reference HousekeepingRoomReadiness.'
        );
        $this->assertStringNotContainsString(
            'room_readiness',
            $source,
            'Handoff model must not reference room_readiness.'
        );

        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffDeliveryService.php');
        $this->assertFileExists($servicePath);
        $source2 = file_get_contents($servicePath);

        // The word "Housekeeping" appears in class/enum names — that is expected.
        // The prohibition is against importing or mutating Housekeeping domain models.
        $this->assertStringNotContainsString(
            'Modules\\Operations\\Housekeeping',
            $source2,
            'Delivery service must not import Housekeeping module classes.'
        );
        $this->assertStringNotContainsString(
            'HousekeepingRoomReadiness',
            $source2,
            'Delivery service must not reference HousekeepingRoomReadiness.'
        );
    }

    // ── No room mutation ──────────────────────────────────────────────────

    public function test_no_room_mutation_in_handoff_code(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffDeliveryService.php');
        $this->assertFileExists($servicePath);
        $source = file_get_contents($servicePath);

        $this->assertStringNotContainsString(
            'Room::',
            $source,
            'Delivery service must not reference Room model.'
        );
        $this->assertStringNotContainsString(
            'rooms',
            $source,
            'Delivery service must not reference rooms table.'
        );
    }

    // ── No Engineering mutation ───────────────────────────────────────────

    public function test_no_engineering_mutation_in_handoff_code(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffDeliveryService.php');
        $this->assertFileExists($servicePath);
        $source = file_get_contents($servicePath);

        $this->assertStringNotContainsString(
            'Engineering',
            $source,
            'Delivery service must not reference Engineering.'
        );
    }

    // ── No frontend ───────────────────────────────────────────────────────

    public function test_no_react_typescript_handoff_action_exists(): void
    {
        $jsBase = base_path('resources/js');
        if (! is_dir($jsBase)) {
            $this->markTestSkipped('resources/js directory not found.');
            return;
        }

        $this->assertNoPatternInDirectory(
            $jsBase,
            ['housekeepingHandoff', 'HousekeepingHandoff', 'housekeeping-handoff', 'checkoutHandoff'],
            'React/TypeScript must not contain checkout Housekeeping handoff actions.'
        );
    }

    // ── No raw PII fields ─────────────────────────────────────────────────

    public function test_no_raw_pii_fields_in_handoff_migration(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $piiPatterns = ['guest_name', 'guest_email', 'guest_phone', 'guest_address',
                        'first_name', 'last_name', 'email', 'phone', 'passport',
                        'credit_card', 'payment_method'];
        foreach ($piiPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $source,
                "Migration must not contain PII field: {$pattern}"
            );
        }
    }

    // ── No financial snapshot fields ──────────────────────────────────────

    public function test_no_financial_snapshot_fields_in_handoff_migration(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $finPatterns = ['amount', 'currency', 'balance', 'folio', 'payment',
                        'deposit', 'refund', 'settlement', 'charge', 'tax_amount',
                        'revenue', 'invoice'];
        foreach ($finPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $source,
                "Migration must not contain financial field: {$pattern}"
            );
        }
    }

    // ── can_execute=false remains ─────────────────────────────────────────

    public function test_can_execute_remains_false(): void
    {
        $boundaryPath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskDepartureCheckoutExecutionBoundaryProjectionService.php');
        $this->assertFileExists($boundaryPath);

        $source = file_get_contents($boundaryPath);
        $this->assertStringContainsString('$canExecute = false;', $source);
        $this->assertStringNotContainsString('$canExecute = true;', $source);
    }

    // ── CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED remains ────────────────────

    public function test_checkout_execution_not_yet_implemented_remains(): void
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

    // ── Contract Version remains 1.13 ─────────────────────────────────────

    public function test_contract_version_remains_1_14(): void
    {
        $contractPath = base_path('.agents/contracts/IVORQ-Package-Execution-Contract.md');
        $this->assertFileExists($contractPath);

        $source = file_get_contents($contractPath);
        $this->assertStringContainsString('Version: 1.14', $source, 'Contract Version must remain 1.14.');
    }

    // ── Package 8 / 9 remain locked ──────────────────────────────────────

    public function test_package_9_source_does_not_exist(): void
    {
        $fdBase = base_path('Modules/Operations/FrontDesk');

        // Package 9: Final checkout command
        $this->assertFileDoesNotExist(
            $fdBase . '/Commands/CheckoutStayCommand.php',
            'No checkout command (Package 9) must exist.'
        );
    }

    // ── Dedicated table — not outbox_messages ─────────────────────────────

    public function test_handoff_uses_dedicated_table_not_outbox_messages(): void
    {
        $modelPath = base_path('Modules/Operations/FrontDesk/Models/FrontDeskCheckoutHousekeepingHandoff.php');
        $this->assertFileExists($modelPath);
        $source = file_get_contents($modelPath);

        $this->assertStringContainsString(
            'front_desk_checkout_housekeeping_handoffs',
            $source,
            'Model must reference dedicated table front_desk_checkout_housekeeping_handoffs.'
        );
        $this->assertStringNotContainsString(
            'outbox_messages',
            $source,
            'Model must not use outbox_messages table.'
        );

        // Verify model does not extend or use OutboxMessage
        $this->assertStringNotContainsString(
            'OutboxMessage',
            $source,
            'Model must not reference OutboxMessage.'
        );
    }

    // ── outbox_messages migration is unchanged ───────────────────────────

    public function test_outbox_messages_migration_is_unchanged(): void
    {
        $migrationPath = base_path('Modules/Foundation/Outbox/database/migrations/2026_06_27_000000_create_outbox_messages_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $this->assertStringContainsString(
            'source_inventory_transaction_id',
            $source,
            'outbox_messages must still contain source_inventory_transaction_id.'
        );
        $this->assertStringContainsString(
            'payload',
            $source,
            'outbox_messages must still contain payload (JSON).'
        );
        $this->assertStringNotContainsString(
            'front_desk_stay_id',
            $source,
            'outbox_messages must not contain Front Desk fields.'
        );
        $this->assertStringNotContainsString(
            'checkout_execution_id',
            $source,
            'outbox_messages must not contain checkout_execution_id.'
        );
    }

    // ── OutboxMessage model is unchanged ──────────────────────────────────

    public function test_outbox_message_model_is_unchanged(): void
    {
        $modelPath = base_path('Modules/Foundation/Outbox/Models/OutboxMessage.php');
        $this->assertFileExists($modelPath);
        $source = file_get_contents($modelPath);

        // OutboxMessage uses Laravel's default table naming (no explicit $table property needed)
        // Verify it does NOT contain any Front Desk or Housekeeping references
        $this->assertStringNotContainsString(
            'FrontDesk',
            $source,
            'OutboxMessage must not reference Front Desk.'
        );
        $this->assertStringNotContainsString(
            'Housekeeping',
            $source,
            'OutboxMessage must not reference Housekeeping.'
        );
        $this->assertStringNotContainsString(
            'front_desk_checkout_housekeeping_handoffs',
            $source,
            'OutboxMessage must not reference the new handoff table.'
        );
        // Verify it still uses HasUlid and OutboxStatusEnum
        $this->assertStringContainsString(
            'HasUlid',
            $source,
            'OutboxMessage must still use HasUlid trait.'
        );
        $this->assertStringContainsString(
            'OutboxStatusEnum',
            $source,
            'OutboxMessage must still use OutboxStatusEnum.'
        );
    }

    // ── No ADR changes ───────────────────────────────────────────────────

    public function test_no_new_adr_files_beyond_adr_089(): void
    {
        $adrDir = base_path('docs/architecture/adr');
        $allAdrFiles = glob($adrDir . '/ADR-*.md');

        $this->assertNotEmpty($allAdrFiles, 'ADR directory must contain files.');

        foreach ($allAdrFiles as $file) {
            $adrName = basename($file);
            if (preg_match('/^ADR-(\d+)-/', $adrName, $m)) {
                $num = (int) $m[1];
                $this->assertLessThanOrEqual(
                    89,
                    $num,
                    "Unexpected ADR file: {$adrName}. Only ADR-001 through ADR-089 are authorized."
                );
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
        foreach ($files as $file) {
            if (str_contains($file, 'tests')
                || str_contains($file, 'database/migrations')
                || str_contains($file, 'Test.php')) {
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

    private function assertNoProductionCreateCall(string $moduleDir, string $call, string $message): void
    {
        $allPhpFiles = [];
        $dirs = [
            base_path('Modules'),
            base_path('app'),
        ];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($rii as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    // Exclude tests, migrations, and seeds
                    if (str_contains($path, 'tests') || str_contains($path, 'database')
                        || str_contains($path, 'Test.php')) {
                        continue;
                    }
                    $allPhpFiles[] = $path;
                }
            }
        }
        foreach ($allPhpFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, $call)) {
                $this->fail("{$message} Found '{$call}' in {$file}.");
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return string[]
     */
    private function scanPhpTsxFiles(string $dir): array
    {
        $files = [];
        if (! is_dir($dir)) {
            return $files;
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'ts', 'tsx', 'js', 'jsx'], true)) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    // ── Correction: CurrentPropertyService usage ──────────────────────────

    public function test_delivery_service_uses_current_property_service_resolve_or_fail(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffDeliveryService.php');
        $this->assertFileExists($servicePath);
        $source = file_get_contents($servicePath);

        $this->assertStringContainsString(
            'resolveOrFail',
            $source,
            'Delivery service must use CurrentPropertyService::resolveOrFail.'
        );
    }

    public function test_delivery_service_caller_property_not_authoritative(): void
    {
        $servicePath = base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutHousekeepingHandoffDeliveryService.php');
        $this->assertFileExists($servicePath);
        $source = file_get_contents($servicePath);

        $this->assertStringContainsString(
            '$propertyId !== $currentPropertyId',
            $source,
            'Delivery service must compare caller propertyId to authoritative current property.'
        );

        $this->assertStringContainsString(
            'forProperty($currentPropertyId)',
            $source,
            'Delivery service must scope queries with the authoritative current property.'
        );
    }

    public function test_migration_contains_full_transition_enforcement(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        $this->assertStringContainsString(
            'NEW.attempts <> OLD.attempts + 1',
            $source,
            'Migration trigger must enforce attempts increment.'
        );
        $this->assertStringContainsString(
            'NEW IS DISTINCT FROM OLD',
            $source,
            'Migration trigger must enforce DELIVERED no-data replay.'
        );
    }

    public function test_trigger_uses_database_clock_authority(): void
    {
        $migrationPath = base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php');
        $this->assertFileExists($migrationPath);
        $source = file_get_contents($migrationPath);

        // Database wall-clock normalized to UTC
        $this->assertStringContainsString(
            "wall_clock_utc := clock_timestamp() AT TIME ZONE 'UTC'",
            $source,
            'Trigger must resolve clock_timestamp() AT TIME ZONE UTC into wall_clock_utc variable.'
        );
        $this->assertStringContainsString(
            'OLD.available_at > wall_clock_utc',
            $source,
            'Trigger must use wall_clock_utc for available_at due-time check.'
        );
        $this->assertStringContainsString(
            'OLD.claim_expires_at <= wall_clock_utc',
            $source,
            'Trigger must use wall_clock_utc for lease expiry guard (DELIVERED/FAILED).'
        );

        // Column-value checks are complementary defense-in-depth
        $this->assertStringContainsString(
            'NEW.claimed_at < OLD.claim_expires_at',
            $source,
            'Trigger must retain column-value claim-expiry comparison.'
        );
        $this->assertStringContainsString(
            'NEW.claimed_at < OLD.available_at',
            $source,
            'Trigger must retain column-value available-at comparison.'
        );
    }
}

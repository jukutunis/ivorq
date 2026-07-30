<?php

namespace Tests\Postgres\Validation;

use Tests\PostgresTestCase;

class RegressionBaselineManifestTest extends PostgresTestCase
{
    private string $manifestPath;

    /** @var object */
    private $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = base_path('scripts/validation/ivorq-regression-baselines.json');

        if (!file_exists($this->manifestPath)) {
            $this->fail("Manifest file not found: {$this->manifestPath}");
        }

        $content = file_get_contents($this->manifestPath);
        $this->manifest = json_decode($content);

        if ($this->manifest === null) {
            $this->fail('Manifest JSON is invalid: ' . json_last_error_msg());
        }
    }

    public function test_manifest_json_is_valid(): void
    {
        $content = file_get_contents($this->manifestPath);
        $decoded = json_decode($content);

        $this->assertNotNull($decoded, 'Manifest JSON must be valid. Error: ' . json_last_error_msg());
        $this->assertIsObject($decoded, 'Manifest root must be an object.');
    }

    public function test_manifest_has_required_top_level_fields(): void
    {
        $this->assertObjectHasProperty('$schema', $this->manifest);
        $this->assertObjectHasProperty('version', $this->manifest);
        $this->assertObjectHasProperty('baselines', $this->manifest);
        $this->assertIsArray($this->manifest->baselines);
        $this->assertNotEmpty($this->manifest->baselines, 'Manifest must contain at least one baseline.');
    }

    public function test_required_baseline_ids_exist(): void
    {
        $requiredIds = [
            'frontdesk-operational-baseline',
            'housekeeping-room-readiness-baseline',
            'engineering-availability-baseline',
            'general-cashier-checkout-obligation-baseline',
            'guest-deposit-refund-ar-transfer-baseline',
            'inventory-avco-sensitive-baseline-v2-candidate',
            'inventory-reversal-inherited-debt-v1',
            'banking-master-baseline-v2-candidate',
            'business-date-foundation-baseline',
            'night-audit-run-lock-foundation-baseline',
            'night-audit-checkout-concurrency-foundation-baseline',
            'guest-ledger-terminal-financial-attestation-baseline',
            'general-cashier-terminal-obligation-attestation-baseline',
        ];

        $actualIds = array_map(fn($b) => $b->id, $this->manifest->baselines);

        foreach ($requiredIds as $id) {
            $this->assertContains(
                $id,
                $actualIds,
                "Required baseline ID '{$id}' is missing from manifest."
            );
        }
    }

    public function test_no_baseline_uses_broad_module_filters(): void
    {
        $forbiddenFilters = [
            'Banking',
            'Inventory',
            'Finance',
            'FrontDesk',
            'Housekeeping',
            'Engineering',
            'CostControl',
        ];

        foreach ($this->manifest->baselines as $baseline) {
            $this->assertEquals(
                'exact-test-classes',
                $baseline->selection_policy ?? '',
                "Baseline '{$baseline->id}' must use selection_policy 'exact-test-classes'."
            );

            foreach ($baseline->classes as $class) {
                $this->assertStringNotContainsString(
                    '*',
                    $class,
                    "Baseline '{$baseline->id}' class '{$class}' must not contain wildcard characters."
                );

                $this->assertStringEndsWith(
                    'Test',
                    $class,
                    "Baseline '{$baseline->id}' class '{$class}' must end with 'Test' (must be an exact test class name)."
                );

                $this->assertNotContains(
                    $class,
                    $forbiddenFilters,
                    "Baseline '{$baseline->id}' class '{$class}' appears to be a broad module filter, not an exact class name."
                );
            }
        }
    }

    public function test_candidate_baselines_are_explicitly_labeled(): void
    {
        foreach ($this->manifest->baselines as $baseline) {
            if (str_contains($baseline->id, 'candidate')) {
                $this->assertEquals(
                    'candidate',
                    $baseline->status,
                    "Baseline '{$baseline->id}' has 'candidate' in its ID but status is '{$baseline->status}', not 'candidate'."
                );
            }
        }
    }

    public function test_inherited_debt_baseline_names_exact_inventory_reversal_workspace_test(): void
    {
        $debtBaseline = $this->findBaseline('inventory-reversal-inherited-debt-v1');
        $this->assertNotNull($debtBaseline, 'inventory-reversal-inherited-debt-v1 baseline must exist.');

        $this->assertContains(
            'InventoryReversalWorkspaceTest',
            $debtBaseline->classes,
            'inventory-reversal-inherited-debt-v1 must contain InventoryReversalWorkspaceTest.'
        );

        $this->assertCount(
            1,
            $debtBaseline->classes,
            'inventory-reversal-inherited-debt-v1 must contain exactly one class: InventoryReversalWorkspaceTest.'
        );

        $this->assertEquals(8, $debtBaseline->expected->tests ?? null);
        $this->assertEquals(72, $debtBaseline->expected->assertions ?? null);
        $this->assertEquals(0, $debtBaseline->expected->failures ?? null);
        $this->assertEquals(2, $debtBaseline->expected->errors ?? null);
    }

    public function test_no_baseline_contains_forbidden_commands_or_secrets(): void
    {
        $forbiddenPatterns = [
            'migrate:fresh',
            'db:seed',
            'migrate --seed',
            'DB_PASSWORD',
            'DB_USERNAME',
            'git reset',
            'git clean',
            'git push --force',
            'TRUNCATE',
            'DROP DATABASE',
        ];

        $manifestJson = file_get_contents($this->manifestPath);

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $manifestJson,
                "Manifest JSON must not contain forbidden pattern: '{$pattern}'."
            );
        }
    }

    public function test_inventory_reversal_workspace_test_not_in_avco_sensitive_candidate(): void
    {
        $candidate = $this->findBaseline('inventory-avco-sensitive-baseline-v2-candidate');
        $this->assertNotNull($candidate, 'inventory-avco-sensitive-baseline-v2-candidate must exist.');

        $this->assertNotContains(
            'InventoryReversalWorkspaceTest',
            $candidate->classes,
            'inventory-avco-sensitive-baseline-v2-candidate must NOT contain InventoryReversalWorkspaceTest (it has its own debt baseline).'
        );
    }

    public function test_pms_cashiering_guest_payment_baseline_counts_are_corrected(): void
    {
        $baseline = $this->findBaseline('pms-cashiering-guest-payment-baseline');
        $this->assertNotNull($baseline, 'pms-cashiering-guest-payment-baseline must exist.');
        $this->assertEquals(53, $baseline->expected->tests ?? null);
        $this->assertEquals(287, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals(
            '137ff01f01268541af05f4e6ef3b330294cf2781',
            $baseline->provenance->sha ?? null
        );
    }

    public function test_general_cashier_checkout_obligation_baseline_matches_gc_a1_measurement(): void
    {
        $baseline = $this->findBaseline('general-cashier-checkout-obligation-baseline');
        $this->assertNotNull($baseline, 'general-cashier-checkout-obligation-baseline must exist.');
        $this->assertEquals('active', $baseline->status ?? null);
        $this->assertSame([
            'GeneralCashierCheckoutObligationProjectionTest',
        ], $baseline->classes);
        $this->assertEquals(38, $baseline->expected->tests ?? null);
        $this->assertEquals(231, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals(
            '0313ed1bb58eaa9f977e761e67cca327469b12b7',
            $baseline->provenance->sha ?? null
        );
        $this->assertEquals(
            'sprint-gc-a1-checkout-cashier-obligation-projection',
            $baseline->provenance->branch ?? null
        );
    }

    public function test_frontdesk_operational_baseline_matches_package_9_measurement(): void
    {
        $baseline = $this->findBaseline('frontdesk-operational-baseline');
        $this->assertNotNull($baseline, 'frontdesk-operational-baseline must exist.');
        $this->assertEquals('active', $baseline->status ?? null);
        $this->assertCount(68, $baseline->classes, 'Front Desk baseline must have exactly 68 classes.');

        // The last twelve classes are the FD-C2, Package 8 confirmation, and corrected Package 9 execution tests.
        $lastTwelve = array_slice($baseline->classes, -12);
        $this->assertSame([
            'FrontDeskCheckoutHousekeepingHandoffFoundationTest',
            'FrontDeskCheckoutHousekeepingHandoffMigrationProofTest',
            'FrontDeskCheckoutHousekeepingHandoffSourceIntegrityTest',
            'FrontDeskCheckoutConfirmationAuthorizationFoundationTest',
            'FrontDeskCheckoutConfirmationMigrationProofTest',
            'FrontDeskCheckoutConfirmationSourceIntegrityTest',
            'FrontDeskCheckoutConfirmationIsolatedConcurrencyProofTest',
            'FrontDeskCheckoutExecutionFoundationTest',
            'FrontDeskCheckoutExecutionRollbackTest',
            'FrontDeskCheckoutExecutionHttpTest',
            'FrontDeskCheckoutExecutionSourceIntegrityTest',
            'FrontDeskCheckoutExecutionIsolatedConcurrencyProofTest',
        ], $lastTwelve);

        $this->assertEquals(729, $baseline->expected->tests ?? null);
        $this->assertEquals(5569, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals(
            '6f0f82c04d78b6513f3fe64e7abbd4d84d0639b1',
            $baseline->provenance->sha ?? null
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{40}$/',
            $baseline->provenance->sha ?? '',
            'Provenance SHA must be a full 40-character hex string.'
        );
        $this->assertEquals(
            'sprint-package-11-housekeeping-checkout-turnover-intake',
            $baseline->provenance->branch ?? null
        );

        // ── FD-C1 predecessor assertions (restored from canonical) ──────────
        $this->assertStringContainsString('FD-C1', $baseline->description ?? '');
        $this->assertStringContainsString('immutable checkout execution evidence', $baseline->description ?? '');
        $this->assertStringContainsString('referential integrity', $baseline->description ?? '');
        $this->assertStringContainsString('Package 9 controlled checkout execution', $baseline->description ?? '');
        $this->assertStringContainsString('FD-B12', $baseline->description ?? '');
        $this->assertStringContainsString('Departure Preparation (FD-A1, FD-A2, FD-B1, FD-B2)', $baseline->description ?? '');

        $this->assertStringContainsString('CHECKED_OUT', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 9 final runtime proof closure', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('FrontDeskCheckoutExecutionService', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No new accepted debt', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('729 tests / 5569 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('2,250,203ms', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Exit code: 0', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 9 isolated concurrency passed 15 tests / 417 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('three distinct PostgreSQL transaction IDs', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 9 focused batch passed 41 tests / 708 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('authorization-first zero requested-stay query proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Scenario I runtime revalidation telemetry', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('execution-route idempotency conflict proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Adjacent NA-A2 + GLF-E + registered GC-A2 authority measurement passed 150 tests / 1447 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('zero Package 9 disposable database residue', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('ControlledGoodsReceiptPostingTest.php:208', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 adjacent Front Desk synchronization', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Version 1.17', $baseline->provenance->note ?? '');

        // ── FD-C2 assertions ──────────────────────────────────────────────
        $this->assertStringContainsString('FD-C2', $baseline->description ?? '');
        $this->assertStringContainsString('checkout-to-Housekeeping handoff/outbox foundation', $baseline->description ?? '');
        $this->assertStringContainsString('dedicated checkout-specific persistence', $baseline->description ?? '');
        $this->assertStringContainsString('no Housekeeping readiness mutation', $baseline->description ?? '');

        $this->assertStringContainsString('checkout-to-Housekeeping handoff', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no Housekeeping readiness mutation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No new accepted debt', $baseline->provenance->note ?? '');

        // ── FD-C2 correction markers ──────────────────────────────────────
        $this->assertStringContainsString('Package 8 checkout confirmation plus execute authorization foundation', $baseline->description ?? '');
        $this->assertStringContainsString('Package 9 controlled checkout execution', $baseline->description ?? '');
        $this->assertStringContainsString('checkout-confirmation POST route', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('checkout-execution POST route', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('durable issuance/consumption evidence', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('real HTTP confirmation-to-execution lifecycle', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('runtime SQLSTATE telemetry assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 touched FD-C2 claim-next delivery behavior', $baseline->provenance->note ?? '');
        $this->assertStringNotContainsString('claimCurrentSessionConfirmationFromPreflight', $baseline->provenance->note ?? '');

        // Prove no arithmetic-derived claims remain in the accepted note
        $this->assertStringNotContainsString('did not complete', $baseline->provenance->note ?? '');
        $this->assertStringNotContainsString('arithmetic from independently verified focused results', $baseline->provenance->note ?? '');
        $this->assertStringNotContainsString('No CURRENT_TIMESTAMP database-clock checks in trigger', $baseline->provenance->note ?? '');
    }

    public function test_housekeeping_room_readiness_baseline_includes_package_11_turnover_intake(): void
    {
        $baseline = $this->findBaseline('housekeeping-room-readiness-baseline');
        $this->assertNotNull($baseline, 'housekeeping-room-readiness-baseline must exist.');
        $this->assertEquals('active', $baseline->status ?? null);
        $this->assertCount(12, $baseline->classes, 'Housekeeping baseline must have exactly 12 classes after Package 11.');

        $this->assertSame([
            'HousekeepingCheckoutTurnoverIntakeFoundationTest',
            'HousekeepingCheckoutTurnoverIntakeMigrationProofTest',
            'HousekeepingCheckoutTurnoverIntakeSourceIntegrityTest',
            'HousekeepingCheckoutTurnoverConsumerCommandTest',
            'HousekeepingCheckoutTurnoverIntakeIsolatedConcurrencyProofTest',
        ], array_slice($baseline->classes, -5));

        $this->assertEquals(86, $baseline->expected->tests ?? null);
        $this->assertEquals(924, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('6f0f82c04d78b6513f3fe64e7abbd4d84d0639b1', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-package-11-housekeeping-checkout-turnover-intake', $baseline->provenance->branch ?? null);
        $this->assertStringContainsString('12 exact classes / 86 tests / 924 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 focused batch passed 23 tests / 703 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('scenarios A-J', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('distinct PHP and PostgreSQL backend PIDs', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('CleaningTask and Room/RoomService passed 46 tests / 97 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No new accepted debt', $baseline->provenance->note ?? '');
    }

    public function test_guest_deposit_refund_ar_transfer_baseline_matches_commit_one_measurement(): void
    {
        $baseline = $this->findBaseline('guest-deposit-refund-ar-transfer-baseline');
        $this->assertNotNull($baseline);
        $this->assertSame([
            'GuestDepositLifecycleTest',
            'GuestRefundLifecycleTest',
            'GuestArTransferLifecycleTest',
            'GuestDepositRefundArTransferConcurrencyProofTest',
            'GuestDepositRefundArTransferMigrationProofTest',
        ], $baseline->classes);
        $this->assertEquals(30, $baseline->expected->tests ?? null);
        $this->assertEquals(326, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('546041416a709194f23010b5395d8acfe4a9d9bb', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-package-9-final-checkout-execution', $baseline->provenance->branch ?? null);
        $this->assertStringContainsString('GUEST_DEPOSIT_OVER_APPLICATION', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('BOUNDED_REJECT', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Full active registry runner passed 14 baselines / 0 failed / 0 skipped', $baseline->provenance->note ?? '');
        $this->assertEquals('active', $baseline->status ?? null);
    }

    public function test_banking_master_candidate_excludes_migration_tests(): void
    {
        $candidate = $this->findBaseline('banking-master-baseline-v2-candidate');
        $this->assertNotNull($candidate, 'banking-master-baseline-v2-candidate must exist.');

        foreach ($candidate->classes as $class) {
            $this->assertStringNotContainsString(
                'BankingMigration',
                $class,
                "banking-master-baseline-v2-candidate must not contain Banking migration test '{$class}'."
            );
        }
    }

    public function test_every_baseline_has_required_fields(): void
    {
        $requiredFields = ['id', 'description', 'type', 'configuration', 'selection_policy', 'execution_mode', 'classes', 'expected', 'status'];

        foreach ($this->manifest->baselines as $baseline) {
            foreach ($requiredFields as $field) {
                $this->assertObjectHasProperty(
                    $field,
                    $baseline,
                    "Baseline '{$baseline->id}' is missing required field '{$field}'."
                );
            }

            $this->assertObjectHasProperty('failures', $baseline->expected, "Baseline '{$baseline->id}' expected is missing 'failures'.");
            $this->assertObjectHasProperty('errors', $baseline->expected, "Baseline '{$baseline->id}' expected is missing 'errors'.");

            $this->assertEquals('phpunit', $baseline->type, "Baseline '{$baseline->id}' type must be 'phpunit'.");
            $this->assertEquals('phpunit.pg.xml', $baseline->configuration, "Baseline '{$baseline->id}' configuration must be 'phpunit.pg.xml'.");

            $validStatuses = ['active', 'candidate', 'legacy-undiscoverable', 'deferred'];
            $this->assertContains(
                $baseline->status,
                $validStatuses,
                "Baseline '{$baseline->id}' status '{$baseline->status}' is not valid. Must be one of: " . implode(', ', $validStatuses) . "."
            );
        }
    }

    // -----------------------------------------------------------------
    // Runner hardening integrity tests (PR #3 governance hardening)
    // -----------------------------------------------------------------

    public function test_execution_mode_valid_on_all_baselines(): void
    {
        $validModes = ['batch', 'individual'];

        foreach ($this->manifest->baselines as $baseline) {
            $this->assertObjectHasProperty(
                'execution_mode',
                $baseline,
                "Baseline '{$baseline->id}' must have an execution_mode field."
            );

            $this->assertContains(
                $baseline->execution_mode,
                $validModes,
                "Baseline '{$baseline->id}' execution_mode '{$baseline->execution_mode}' is not valid. Must be one of: " . implode(', ', $validModes) . "."
            );
        }
    }

    public function test_inventory_avco_sensitive_candidate_uses_individual_execution(): void
    {
        $candidate = $this->findBaseline('inventory-avco-sensitive-baseline-v2-candidate');
        $this->assertNotNull($candidate, 'inventory-avco-sensitive-baseline-v2-candidate must exist.');

        $this->assertEquals(
            'individual',
            $candidate->execution_mode,
            'inventory-avco-sensitive-baseline-v2-candidate must use execution_mode=individual to avoid RefreshDatabase batch conflicts.'
        );
    }

    public function test_active_baselines_have_non_null_expected_tests_and_assertions(): void
    {
        foreach ($this->manifest->baselines as $baseline) {
            if ($baseline->status === 'active') {
                $this->assertNotNull(
                    $baseline->expected->tests ?? null,
                    "Active baseline '{$baseline->id}' must have non-null expected.tests."
                );
                $this->assertNotNull(
                    $baseline->expected->assertions ?? null,
                    "Active baseline '{$baseline->id}' must have non-null expected.assertions."
                );
            }
        }
    }

    public function test_accepted_debt_expected_errors_equals_canonical_expected_errors(): void
    {
        foreach ($this->manifest->baselines as $baseline) {
            if (empty($baseline->accepted_debt)) {
                continue;
            }

            $debtErrorSum = 0;
            foreach ($baseline->accepted_debt as $debt) {
                if (isset($debt->expected_errors)) {
                    $debtErrorSum += $debt->expected_errors;
                }
            }

            if ($debtErrorSum > 0) {
                $this->assertEquals(
                    $baseline->expected->errors,
                    $debtErrorSum,
                    "Baseline '{$baseline->id}': accepted_debt expected_errors sum ({$debtErrorSum}) must equal expected.errors ({$baseline->expected->errors}). expected.errors is the canonical total; accepted_debt is explanatory metadata only."
                );
            }
        }
    }

    public function test_inventory_reversal_inherited_debt_expected_errors_exactly_2(): void
    {
        $debt = $this->findBaseline('inventory-reversal-inherited-debt-v1');
        $this->assertNotNull($debt, 'inventory-reversal-inherited-debt-v1 must exist.');

        $this->assertEquals(
            2,
            $debt->expected->errors,
            'inventory-reversal-inherited-debt-v1 expected.errors must remain exactly 2. Do not change this value without explicit owner authorization.'
        );
    }

    public function test_non_empty_classes_baselines_have_positive_expected_tests_when_measured(): void
    {
        // Baselines with non-empty classes: if expected.tests is measured (non-null),
        // it must be > 0. A non-empty class list that produces 0 selected tests
        // is a runner NO_TESTS_SELECTED failure.
        // Candidate baselines may have null expected.tests (not yet measured),
        // but the runner must still reject zero selected tests at runtime.
        foreach ($this->manifest->baselines as $baseline) {
            if (count($baseline->classes) > 0 && isset($baseline->expected->tests) && $baseline->expected->tests !== null) {
                $this->assertGreaterThan(
                    0,
                    $baseline->expected->tests,
                    "Baseline '{$baseline->id}' has " . count($baseline->classes) . " class(es) but expected.tests is {$baseline->expected->tests}. Non-empty classes must produce non-zero tests."
                );
            }
        }
    }

    public function test_candidate_baseline_with_null_expected_still_has_non_empty_classes_for_runner_rejection(): void
    {
        // Candidate baselines may have null expected.tests/assertions, but they MUST
        // still have non-empty classes so the runner can select tests. A candidate
        // with non-empty classes and null expected counts is valid — the runner
        // will reject zero selected tests at runtime. A candidate with empty classes
        // and null expected counts is vacuous and should not exist.
        foreach ($this->manifest->baselines as $baseline) {
            if ($baseline->status === 'candidate') {
                $this->assertNotEmpty(
                    $baseline->classes,
                    "Candidate baseline '{$baseline->id}' must have non-empty classes. Candidate baselines that select zero tests cannot be evaluated."
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Active/candidate selection integrity tests (PR #3 final fix)
    // -----------------------------------------------------------------

    public function test_active_baselines_are_the_only_default_acceptance_gates(): void
    {
        $activeIds = [];
        foreach ($this->manifest->baselines as $baseline) {
            if ($baseline->status === 'active') {
                $activeIds[] = $baseline->id;
            }
        }

        $expectedActiveIds = [
            'frontdesk-operational-baseline',
            'guest-ledger-folio-aggregate-baseline',
            'pms-cashiering-guest-payment-baseline',
            'general-cashier-checkout-obligation-baseline',
            'guest-deposit-refund-ar-transfer-baseline',
            'housekeeping-room-readiness-baseline',
            'engineering-availability-baseline',
            'inventory-reversal-inherited-debt-v1',
            'guest-ledger-settlement-readiness-baseline',
            'business-date-foundation-baseline',
            'night-audit-run-lock-foundation-baseline',
            'night-audit-checkout-concurrency-foundation-baseline',
            'guest-ledger-terminal-financial-attestation-baseline',
            'general-cashier-terminal-obligation-attestation-baseline',
        ];

        $this->assertCount(
            14,
            $activeIds,
            "Must have exactly 14 active baselines. Found: " . implode(', ', $activeIds)
        );

        foreach ($expectedActiveIds as $id) {
            $this->assertContains(
                $id,
                $activeIds,
                "Required active baseline '{$id}' is missing or not active."
            );
        }
    }

    public function test_candidate_baselines_are_not_active_gates(): void
    {
        $candidateIds = [];
        foreach ($this->manifest->baselines as $baseline) {
            if ($baseline->status === 'candidate') {
                $candidateIds[] = $baseline->id;
            }
        }

        $expectedCandidateIds = [
            'inventory-avco-sensitive-baseline-v2-candidate',
            'banking-master-baseline-v2-candidate',
        ];

        $this->assertCount(
            2,
            $candidateIds,
            "Must have exactly 2 candidate baselines. Found: " . implode(', ', $candidateIds)
        );

        foreach ($expectedCandidateIds as $id) {
            $this->assertContains(
                $id,
                $candidateIds,
                "Required candidate baseline '{$id}' is missing or not candidate."
            );
        }
    }

    public function test_inventory_avco_sensitive_candidate_remains_candidate(): void
    {
        $candidate = $this->findBaseline('inventory-avco-sensitive-baseline-v2-candidate');
        $this->assertNotNull($candidate, 'inventory-avco-sensitive-baseline-v2-candidate must exist.');
        $this->assertEquals(
            'candidate',
            $candidate->status,
            'inventory-avco-sensitive-baseline-v2-candidate must remain candidate. Do not promote to active without owner approval.'
        );
    }

    public function test_banking_master_candidate_remains_candidate(): void
    {
        $candidate = $this->findBaseline('banking-master-baseline-v2-candidate');
        $this->assertNotNull($candidate, 'banking-master-baseline-v2-candidate must exist.');
        $this->assertEquals(
            'candidate',
            $candidate->status,
            'banking-master-baseline-v2-candidate must remain candidate. Do not promote to active without owner approval.'
        );
    }

    public function test_runner_script_contains_include_candidates_switch(): void
    {
        $runnerPath = base_path('scripts/validation/Invoke-IvorqRegressionBaseline.ps1');
        $this->assertFileExists($runnerPath, 'Runner script must exist at scripts/validation/Invoke-IvorqRegressionBaseline.ps1.');

        $content = file_get_contents($runnerPath);

        $this->assertStringContainsString(
            'IncludeCandidates',
            $content,
            'Runner script must contain IncludeCandidates switch for explicit candidate opt-in.'
        );
    }

    // -----------------------------------------------------------------
    // Remaining original tests
    // -----------------------------------------------------------------

    public function test_no_duplicate_classes_across_baselines_within_same_domain(): void
    {
        $count = 0;
        foreach ($this->manifest->baselines as $baseline) {
            if (in_array('InventoryReversalWorkspaceTest', $baseline->classes)) {
                $count++;
            }
        }

        $this->assertEquals(
            1,
            $count,
            "InventoryReversalWorkspaceTest must appear in exactly one baseline, found in {$count}."
        );
    }

    public function test_manifest_has_complete_baseline_ids_list(): void
    {
        $ids = array_map(fn($b) => $b->id, $this->manifest->baselines);

        $expectedCount = 16;
        $this->assertCount(
            $expectedCount,
            $ids,
            "Manifest must contain exactly {$expectedCount} baselines. Found: " . implode(', ', $ids)
        );
    }

    public function test_business_date_foundation_baseline_matches_bd_a1_measurement(): void
    {
        $baseline = $this->findBaseline('business-date-foundation-baseline');
        $this->assertNotNull($baseline, 'BD-A1 baseline must exist.');
        $this->assertEquals('active', $baseline->status);
        $this->assertSame([
            'PropertyBusinessDateFoundationTest',
            'PropertyBusinessDateInitializationConcurrencyProofTest',
            'PropertyBusinessDateMigrationProofTest',
        ], $baseline->classes);
        $this->assertEquals(11, $baseline->expected->tests ?? null);
        $this->assertEquals(318, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('c4164a936a8d3dcbaa2cb345faba0a8f9f679492', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-bd-a1-authoritative-property-business-date-foundation', $baseline->provenance->branch ?? null);
        $this->assertStringContainsString('BD-A1', $baseline->description ?? '');
        $this->assertStringContainsString('timezone evidence', $baseline->description ?? '');
        $this->assertNotContains('BusinessDateCloseExecutionServiceTest', $baseline->classes);
    }

    public function test_night_audit_run_lock_foundation_baseline_matches_na_a1_measurement(): void
    {
        $baseline = $this->findBaseline('night-audit-run-lock-foundation-baseline');
        $this->assertNotNull($baseline, 'NA-A1 baseline must exist.');
        $this->assertEquals('active', $baseline->status);
        $this->assertSame([
            'NightAuditRunFoundationTest',
            'NightAuditRunConcurrencyProofTest',
            'NightAuditRunMigrationProofTest',
        ], $baseline->classes);
        $this->assertEquals(11, $baseline->expected->tests ?? null);
        $this->assertEquals(422, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('3b79657ddbbf24b7e2a51886f7e91d00cd957d37', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-na-a1-night-audit-run-lock-foundation', $baseline->provenance->branch ?? null);
        $this->assertStringContainsString('NA-A1', $baseline->description ?? '');
        $this->assertStringContainsString('BD-A1 read-only dependency', $baseline->description ?? '');
        $this->assertStringContainsString('authorization-first', $baseline->description ?? '');
        $this->assertStringContainsString('Night Audit run primary key is immutable', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('ID-only mutation is rejected', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('ID mutation cannot be smuggled through the allowed IN_PROGRESS to ABORTED transition', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Valid abort with unchanged identity remains permitted', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('All lock projection and locked-context corrections remain intact', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('complete projection whitelist', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Separates lock status from run lifecycle', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('strict persisted snapshot validation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('locked Property and Business Date revalidation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('NA_A1_ACTIVE_RUN_NOT_FOUND', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('valid idempotent start', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('controlled abort', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No Business Date close, advancement, reopen', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No Business Date close, advancement, reopen, checkpoints, FD-B12, checkout execution', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('checkpoints', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('checkout execution', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('foreign-domain mutation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('previous accepted measurement was 11 tests / 406 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('added exactly two Night Audit PHP files', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('eight assertions per file', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('adding exactly 16 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('final 11 tests / 422 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No NA-A1 test case was added or removed', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no static boundary was weakened', $baseline->provenance->note ?? '');
        $this->assertNotContains('BusinessDateCloseExecutionServiceTest', $baseline->classes);
    }

    public function test_night_audit_checkout_concurrency_foundation_baseline_matches_na_a2_measurement(): void
    {
        $baseline = $this->findBaseline('night-audit-checkout-concurrency-foundation-baseline');
        $this->assertNotNull($baseline, 'NA-A2 baseline must exist.');
        $this->assertEquals('active', $baseline->status);
        $this->assertSame([
            'NightAuditCheckoutConcurrencyFoundationTest',
            'NightAuditCheckoutConcurrencyProofTest',
        ], $baseline->classes);
        $this->assertEquals(20, $baseline->expected->tests ?? null);
        $this->assertEquals(914, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('4b098c62bb2ecfaa49fb0c1e92fb5d4726bc7882', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-na-a2-checkout-transaction-concurrency-foundation', $baseline->provenance->branch ?? null);
        $this->assertStringContainsString('separate PHP processes', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('distinct PostgreSQL backend PIDs', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Property-scoped', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('finite worker timeout and cleanup', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('txid_current transaction ID', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('static WeakMap', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('parameterized set_config with transaction-local scope', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('only its SHA-256 hash is retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('hash_equals verifies the private issuance evidence', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('same backend PID and same outer transaction ID', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('PostgreSQL reverts the capability state', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('before any Night Audit query and all tracked tables remain write-free', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Successful nested savepoint release preserves the capability', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('repeated outer-transaction attestations keep a deterministic fingerprint', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('the newest context succeeds and the prior context is rejected', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('one active NA-A2 capability per transaction', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('generated capability value is absent from the public lock context', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('same backend in a new transaction', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('manually constructed but unissued context', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('before any night_audit_runs query', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('previous database exception retained as cause', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Same-transaction repeated attestations keep a deterministic fingerprint', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('checkout runtime', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('can_execute=false', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('checkout remains unauthorized', $baseline->provenance->note ?? '');
    }

    public function test_glf_d_baseline_has_exact_three_classes_and_correct_counts(): void
    {
        $baseline = $this->findBaseline('guest-ledger-settlement-readiness-baseline');
        $this->assertNotNull($baseline, 'GLF-D baseline must exist.');
        $this->assertEquals('active', $baseline->status);
        $this->assertCount(3, $baseline->classes, 'GLF-D must have exactly 3 classes.');
        $this->assertEquals('GuestLedgerCheckoutSettlementReadinessProjectionTest', $baseline->classes[0]);
        $this->assertEquals('GuestLedgerCheckoutSettlementReadinessSourceIntegrityTest', $baseline->classes[1]);
        $this->assertEquals('GuestLedgerCheckoutSettlementReadinessConcurrencyProofTest', $baseline->classes[2]);
        $this->assertEquals(60, $baseline->expected->tests ?? null, 'GLF-D must have 60 tests.');
        $this->assertEquals(253, $baseline->expected->assertions ?? null, 'GLF-D must have 253 assertions.');
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertEquals([], $baseline->accepted_debt);
        $this->assertEquals('7ebe5eb7063e2ca04ee7c36ad583ea9496c97c37', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-glf-d-checkout-settlement-readiness-projection', $baseline->provenance->branch ?? null);
    }

    public function test_glf_e_baseline_has_exact_three_classes_and_correct_counts(): void
    {
        $baseline = $this->findBaseline('guest-ledger-terminal-financial-attestation-baseline');
        $this->assertNotNull($baseline, 'GLF-E baseline must exist.');
        $this->assertEquals('active', $baseline->status);
        $this->assertCount(3, $baseline->classes, 'GLF-E must have exactly 3 classes.');
        $this->assertEquals('GuestLedgerCheckoutTerminalFinancialAttestationFoundationTest', $baseline->classes[0]);
        $this->assertEquals('GuestLedgerCheckoutTerminalFinancialAttestationSourceIntegrityTest', $baseline->classes[1]);
        $this->assertEquals('GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyProofTest', $baseline->classes[2]);
        $this->assertEquals(63, $baseline->expected->tests ?? null, 'GLF-E must have 63 tests.');
        $this->assertEquals(273, $baseline->expected->assertions ?? null, 'GLF-E must have 273 assertions.');
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertEquals([], $baseline->accepted_debt);
        $this->assertEquals('546041416a709194f23010b5395d8acfe4a9d9bb', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-package-9-final-checkout-execution', $baseline->provenance->branch ?? null);
        $this->assertEquals('batch', $baseline->execution_mode ?? null, 'GLF-E must use batch execution mode.');
        $this->assertStringContainsString('Full active registry runner passed 14 baselines / 0 failed / 0 skipped', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('distinct PHP/PG PIDs', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('zero-write SQL proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Transaction-local GLF-E capability', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('set_config', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no raw capability leakage', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('zero business writes', $baseline->provenance->note ?? '');
    }

    public function test_gc_a2_baseline_matches_proof_commit_measurement(): void
    {
        $baseline = $this->findBaseline('general-cashier-terminal-obligation-attestation-baseline');
        $this->assertNotNull($baseline, 'GC-A2 baseline must exist.');
        $this->assertEquals('active', $baseline->status);
        $this->assertEquals('individual', $baseline->execution_mode ?? null, 'GC-A2 uses individual execution due to DatabaseMigrations batch conflict.');
        $this->assertSame([
            'GeneralCashierCheckoutTerminalObligationAttestationFoundationTest',
            'GeneralCashierCheckoutTerminalObligationAttestationSourceIntegrityTest',
            'GeneralCashierCheckoutTerminalObligationAttestationConcurrencyProofTest',
        ], $baseline->classes);
        $this->assertEquals(67, $baseline->expected->tests ?? null);
        $this->assertEquals(260, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('546041416a709194f23010b5395d8acfe4a9d9bb', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-package-9-final-checkout-execution', $baseline->provenance->branch ?? null);

        // Execution mode (already asserted on the field above)
        $this->assertStringContainsString('registered individual runner total', $baseline->provenance->note ?? '');

        // Per-class results
        $this->assertStringContainsString('Foundation 44 tests / 131 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('SourceIntegrity 18 tests / 73 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('ConcurrencyProof 5 tests / 56 assertions', $baseline->provenance->note ?? '');

        // Registered individual total
        $this->assertStringContainsString('67 tests / 260 assertions / 0 failures / 0 errors / 0 skipped', $baseline->provenance->note ?? '');

        // Combined batch is not registered mode
        $this->assertStringContainsString('Combined batch execution is not the registered mode', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('three DatabaseMigrations classes conflict when combined', $baseline->provenance->note ?? '');

        // Complete active runner
        $this->assertStringContainsString('Full active registry runner passed 14 baselines / 0 failed / 0 skipped', $baseline->provenance->note ?? '');

        // Capability lifecycle
        $this->assertStringContainsString('Transaction-local GC-A2 capability', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Only SHA-256 capability hash retained', $baseline->provenance->note ?? '');

        // Transaction locality
        $this->assertStringContainsString('Active PostgreSQL transaction required', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Parameterized set_config(..., true)', $baseline->provenance->note ?? '');

        // Lock ordering and source
        $this->assertStringContainsString('Deterministic lock order', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('cashier_sessions only FOR UPDATE source', $baseline->provenance->note ?? '');

        // Savepoint rollback
        $this->assertStringContainsString('Savepoint rollback invalidates GC-A2', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Savepoint rollback preserves NA-A2 and GLF-E', $baseline->provenance->note ?? '');

        // Source restrictions
        $this->assertStringContainsString('No GC-A1 reuse', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No PMS financial-source query', $baseline->provenance->note ?? '');

        // Zero business writes
        $this->assertStringContainsString('Zero business writes', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No migration', $baseline->provenance->note ?? '');

        // No route/controller/UI, no permission/confirmation
        $this->assertStringContainsString('No route/controller/UI', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No permission/confirmation', $baseline->provenance->note ?? '');

        // GC-A2 scope
        $this->assertStringContainsString('pg_stat_activity and pg_blocking_pids lock proof retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Exact NA-A2 context required', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Exact GLF-E object required', $baseline->provenance->note ?? '');
    }

    private function findBaseline(string $id): ?object
    {
        foreach ($this->manifest->baselines as $baseline) {
            if ($baseline->id === $id) {
                return $baseline;
            }
        }
        return null;
    }
}

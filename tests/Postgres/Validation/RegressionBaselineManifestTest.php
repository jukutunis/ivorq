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

    public function test_frontdesk_operational_baseline_matches_fd_b10_measurement(): void
    {
        $baseline = $this->findBaseline('frontdesk-operational-baseline');
        $this->assertNotNull($baseline, 'frontdesk-operational-baseline must exist.');
        $this->assertEquals('active', $baseline->status ?? null);
        $this->assertCount(53, $baseline->classes, 'Front Desk baseline must keep exactly 53 classes.');
        $this->assertEquals('FrontDeskDepartureCheckoutExecutionBoundaryTest', $baseline->classes[count($baseline->classes) - 1]);
        $this->assertEquals(409, $baseline->expected->tests ?? null);
        $this->assertEquals(1702, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals(
            'fd14a613c405241d6a7a2ada5dcf127cc18de777',
            $baseline->provenance->sha ?? null
        );
        $this->assertEquals(
            'sprint-fd-b10-general-cashier-obligation-read-integration',
            $baseline->provenance->branch ?? null
        );
        $this->assertStringContainsString('FD-B10', $baseline->description ?? '');
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
        $this->assertEquals('8eca2e7bac4b9e15a0ed93a50f29f43c90a6622e', $baseline->provenance->sha ?? null);
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
        ];

        $this->assertCount(
            9,
            $activeIds,
            "Must have exactly 9 active baselines. Found: " . implode(', ', $activeIds)
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

        $expectedCount = 11;
        $this->assertCount(
            $expectedCount,
            $ids,
            "Manifest must contain exactly {$expectedCount} baselines. Found: " . implode(', ', $ids)
        );
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

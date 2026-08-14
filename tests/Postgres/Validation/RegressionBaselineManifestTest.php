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

        if (! file_exists($this->manifestPath)) {
            $this->fail("Manifest file not found: {$this->manifestPath}");
        }

        $content = file_get_contents($this->manifestPath);
        $this->manifest = json_decode($content);

        if ($this->manifest === null) {
            $this->fail('Manifest JSON is invalid: '.json_last_error_msg());
        }
    }

    public function test_manifest_json_is_valid(): void
    {
        $content = file_get_contents($this->manifestPath);
        $decoded = json_decode($content);

        $this->assertNotNull($decoded, 'Manifest JSON must be valid. Error: '.json_last_error_msg());
        $this->assertIsObject($decoded, 'Manifest root must be an object.');
    }

    public function test_manifest_and_registry_do_not_contain_utf8_mojibake_markers(): void
    {
        $markers = [
            'latin capital a with tilde plus cent sign marker' => json_decode('"\\u00c3\\u00a2"', false, 512, JSON_THROW_ON_ERROR),
            'latin capital a with tilde plus f-hook marker' => json_decode('"\\u00c3\\u0192"', false, 512, JSON_THROW_ON_ERROR),
            'replacement character' => json_decode('"\\ufffd"', false, 512, JSON_THROW_ON_ERROR),
            'latin small i-diaeresis replacement-byte marker' => json_decode('"\\u00ef\\u00bf\\u00bd"', false, 512, JSON_THROW_ON_ERROR),
            'double-encoded em dash marker' => json_decode('"\\u00c3\\u00a2\\u00e2\\u201a\\u00ac\\u00e2\\u20ac\\u009d"', false, 512, JSON_THROW_ON_ERROR),
            'double-encoded right-arrow marker' => json_decode('"\\u00c3\\u00a2\\u00e2\\u20ac\\u00a0\\u00e2\\u20ac\\u2122"', false, 512, JSON_THROW_ON_ERROR),
            'mojibake em dash marker' => json_decode('"\\u00e2\\u20ac\\u201d"', false, 512, JSON_THROW_ON_ERROR),
            'mojibake right-arrow marker' => json_decode('"\\u00e2\\u2020\\u2019"', false, 512, JSON_THROW_ON_ERROR),
        ];

        foreach ([
            $this->manifestPath,
            base_path('docs/validation/IVORQ-Regression-Baseline-Registry.md'),
        ] as $path) {
            $content = file_get_contents($path);
            $this->assertIsString($content, "{$path} must be readable.");
            $this->assertSame(1, preg_match('//u', $content), "{$path} must contain valid UTF-8.");

            foreach ($markers as $label => $marker) {
                $this->assertStringNotContainsString($marker, $content, "{$path} contains UTF-8 mojibake marker: {$label}.");
            }
        }

        $emDash = json_decode('"\\u2014"', false, 512, JSON_THROW_ON_ERROR);
        $rightArrow = json_decode('"\\u2192"', false, 512, JSON_THROW_ON_ERROR);
        $manifestContent = file_get_contents($this->manifestPath);

        $this->assertStringContainsString("Registry {$emDash} exact test-class manifests", $manifestContent);
        $this->assertStringContainsString("Deposit {$rightArrow} Folio {$rightArrow} Application {$rightArrow} Reversal", $manifestContent);

        $inventoryCandidate = $this->findBaseline('inventory-avco-sensitive-baseline-v2-candidate');
        $this->assertNotNull($inventoryCandidate, 'inventory-avco-sensitive-baseline-v2-candidate must exist.');
        $this->assertStringContainsString("candidate {$emDash} replaces", $inventoryCandidate->description ?? '');

        $bankingCandidate = $this->findBaseline('banking-master-baseline-v2-candidate');
        $this->assertNotNull($bankingCandidate, 'banking-master-baseline-v2-candidate must exist.');
        $this->assertStringContainsString("candidate {$emDash} replaces", $bankingCandidate->description ?? '');
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

        $actualIds = array_map(fn ($b) => $b->id, $this->manifest->baselines);

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

    public function test_frontdesk_operational_baseline_matches_package_19_housekeeping_source_scan_delta(): void
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
        $this->assertEquals(5693, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals(
            'a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50',
            $baseline->provenance->sha ?? null
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{40}$/',
            $baseline->provenance->sha ?? '',
            'Provenance SHA must be a full 40-character hex string.'
        );
        $this->assertEquals(
            'sprint-package-19-housekeeping-inspection-claim-recovery-reassignment',
            $baseline->provenance->branch ?? null
        );

        $this->assertEquals('2026-08-14', $baseline->provenance->measured_at ?? null);
        $this->assertSame('PACKAGE_19_INDEPENDENT_REVIEW_CORRECTED_RUNTIME_PROOF', $baseline->provenance->governance_status ?? null);
        $this->assertStringContainsString('Contract Version 1.20', $baseline->provenance->current_governance_note ?? '');
        $this->assertMatchesRegularExpression('/a99f4b20489c3259c416297310a7b02f9cb6dacb.*a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50.*3f05283dc878c9ec098ba0e27b319451abda36ad.*88750a9a23067d1630d0bf151510f0a94083f546/', $baseline->provenance->current_governance_note ?? '');
        $this->assertMatchesRegularExpression('/deterministic server ownership of occurred_at \/ created_at.*expanded direct PostgreSQL malformed-write proof.*no lifecycle redesign.*no Package 17 claim mutation/', $baseline->provenance->current_governance_note ?? '');
        $this->assertMatchesRegularExpression('/729 tests \/ 5693 assertions \/ 0 failures \/ 0 errors.*5657 \+ \(6 x 6\) = 5693/', $baseline->provenance->current_governance_note ?? '');
        $this->assertStringContainsString('Package 18 is governance-only under Contract Version 1.20', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Package 17 is accepted and canonical through PR #51 at merge 37750626f9e0614d26d628a4707bcb205508ae03', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Package 18 governance commit 8cc177066dcd0598e740bea9a70ef756353d1442 and Housekeeping Contract-guard alignment commit df606720148a0a09df12eb111f5ddd79851608ed', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('change no Front Desk runtime and no Housekeeping production source', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('no Package 17 runtime or integrity assertion was weakened', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('exact 68-class Front Desk selection remains unchanged at 729 tests / 5657 assertions / 0 failures / 0 errors', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Package 19 runtime remains locked', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Inventory Reversal inherited debt remains unchanged', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('No new ADR and no new accepted debt', $baseline->provenance->governance_note ?? '');

        $this->assertStringContainsString('729 tests / 5657 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 17 final predecessor-migration isolation source/test commit 98ccdeb9be1b9bc60b2df9cda2d31bbe9aed4a59', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 17 source-scan delta remains exactly 3 x 6 = 18 and 5639 + 18 = 5657', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 17 correction source/harness commit 86a3b9e242bbf427353e07131c42f69d983df6e9', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('original Package 17 source commit 20112b623d04c50655e8701566c1dbd156e6dc53 and metadata commit de3e131c091f02fbb70cabb41006accecb0ce1bd remain retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 17 adds no Front Desk runtime, route, UI, ownership, scheduler, or integration', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('exactly three eligible Housekeeping source files', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('HousekeepingInspectionClaimService.php', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('HousekeepingInspectionClaimResult.php', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('2026_08_11_000001_control_housekeeping_inspection_claims.php', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('3 x 6 = 18 additional meaningful negative assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('5639 + 18 = 5657', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Contract Version 1.19', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 15 remains accepted and merged', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('deterministic Housekeeping source-scan cross-baseline measurement remains 729 tests / 5639 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('historical Package 15 +24 assertion explanation remains preserved', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 16 changes no Front Desk production source and no Housekeeping production source', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Exactly two Front Desk source-integrity Contract guards changed from Version 1.18 to Version 1.19', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('guard changes do not change the exact test count or assertion count', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('valid Package 16 Front Desk measurement remains 729 tests / 5639 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('unchanged exact 68-class selection', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Historical Package 15 provenance and historical PR #48 Contract 1.18 correction provenance are retained', $baseline->provenance->note ?? '');

        $this->assertStringContainsString('Package 15 re-anchor 5e81983ab8443e5903349426c4835c356ba495fe retains the canonical Contract Version 1.18 correction from PR #48', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('canonical isolated predecessor measurement of 729 tests / 5615 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('FrontDeskCheckoutExecutionEvidenceSourceIntegrityTest::test_no_housekeeping_checkout_handoff_or_outbox_exists', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('five eligible Housekeeping source files and removes one', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('net four-file expansion produces exactly 4 x 6 = 24 additional meaningful negative assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('post-Package-15 Front Desk measurement is 729 tests / 5639 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('At that historical PR #48 point, Contract Version 1.18 was canonical and approved', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Two Front Desk source-integrity tests retained stale Version 1.17 guards', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Source commit b6dcdcee6c46c67252e658a16c114007e14b4e99 corrected only those guards to Version 1.18', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('valid isolated Front Desk measurement is 729 tests / 5615 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('exact selection remains 68 classes', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No Front Desk production source changed', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No Front Desk route, UI, ownership, scheduler, or integration changed', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No Front Desk accepted debt was added', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Inventory Reversal inherited debt remains unchanged', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('prior 5639 assertion measurement was rejected', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('vendor junction resolving another worktree', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('rejected 5639 measurement is not canonical evidence', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 15 is excluded from this prerequisite measurement', $baseline->provenance->note ?? '');

        // Historical Package 13 / Package 11 / Package 9 provenance remains guarded.
        $this->assertStringContainsString('Package 13 remeasured the unchanged exact 68-class Front Desk baseline at 729 tests / 5615 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 13 retained the exact 68-class Front Desk selection', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('increased from 5,591 to 5,615', $baseline->provenance->note ?? '');
        $this->assertStringContainsString("Package 13's bounded Housekeeping cleaning/inspection/readiness integration", $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 13 added no Front Desk accepted debt', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Prior Package 12, Package 11, and Package 9 provenance history remains retained', $baseline->provenance->note ?? '');

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
        $this->assertStringContainsString('2,539,194ms', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Exit code: 0', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 9 isolated concurrency passed 14 tests / 383 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('PostgreSQL transaction participation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 9 focused batch passed 41 tests / 708 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('authorization-first zero requested-stay query proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Scenario I runtime revalidation telemetry', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('execution-route idempotency conflict proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('FD-C2 individual gates passed 120 tests / 1361 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Inventory Reversal inherited debt unchanged', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('ControlledGoodsReceiptPostingTest.php:208', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 final controlled recovery', $baseline->provenance->note ?? '');
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
        $this->assertStringContainsString('Package 11 retained FD-C2 claim-next delivery behavior', $baseline->provenance->note ?? '');
        $this->assertStringNotContainsString('claimCurrentSessionConfirmationFromPreflight', $baseline->provenance->note ?? '');

        // Prove no arithmetic-derived claims remain in the accepted note
        $this->assertStringNotContainsString('did not complete', $baseline->provenance->note ?? '');
        $this->assertStringNotContainsString('arithmetic from independently verified focused results', $baseline->provenance->note ?? '');
        $this->assertStringNotContainsString('No CURRENT_TIMESTAMP database-clock checks in trigger', $baseline->provenance->note ?? '');
    }

    public function test_housekeeping_room_readiness_baseline_includes_package_19_controlled_claim_recovery(): void
    {
        $baseline = $this->findBaseline('housekeeping-room-readiness-baseline');
        $this->assertNotNull($baseline, 'housekeeping-room-readiness-baseline must exist.');
        $this->assertEquals('active', $baseline->status ?? null);
        $this->assertCount(34, $baseline->classes, 'Housekeeping baseline must have exactly 34 classes after Package 19.');

        $this->assertSame([
            'HousekeepingCheckoutTurnoverIntakeFoundationTest',
            'HousekeepingCheckoutTurnoverIntakeMigrationProofTest',
            'HousekeepingCheckoutTurnoverIntakeSourceIntegrityTest',
            'HousekeepingCheckoutTurnoverConsumerCommandTest',
            'HousekeepingCheckoutTurnoverIntakeIsolatedConcurrencyProofTest',
            'HousekeepingCheckoutTurnoverWorkspaceTest',
            'HousekeepingCheckoutTurnoverWorkspaceSourceIntegrityTest',
        ], array_slice($baseline->classes, -27, 7));

        $this->assertSame([
            'HousekeepingCleaningInspectionReadinessIntegrationTest',
            'HousekeepingCleaningInspectionReadinessSourceIntegrityTest',
            'HousekeepingCleaningInspectionReadinessIsolatedConcurrencyProofTest',
            'HousekeepingCleaningInspectionReadinessMigrationProofTest',
        ], array_slice($baseline->classes, -20, 4));

        $this->assertSame([
            'HousekeepingControlledDispatchAssignmentTest',
            'HousekeepingControlledDispatchAssignmentSourceIntegrityTest',
            'HousekeepingControlledDispatchAssignmentMigrationProofTest',
            'HousekeepingControlledDispatchAssignmentIsolatedConcurrencyProofTest',
        ], array_slice($baseline->classes, -16, 4));

        $this->assertSame([
            'HousekeepingControlledInspectionClaimSegregationFoundationTest',
            'HousekeepingControlledInspectionClaimSegregationHttpTest',
            'HousekeepingControlledInspectionClaimSegregationMigrationProofTest',
            'HousekeepingControlledInspectionClaimSegregationSourceIntegrityTest',
            'HousekeepingControlledInspectionClaimSegregationWorkspaceTest',
            'HousekeepingControlledInspectionClaimSegregationIsolatedConcurrencyProofTest',
        ], array_slice($baseline->classes, -12, 6));

        $this->assertSame([
            'HousekeepingControlledInspectionClaimRecoveryFoundationTest',
            'HousekeepingControlledInspectionClaimRecoveryHttpTest',
            'HousekeepingControlledInspectionClaimRecoveryMigrationProofTest',
            'HousekeepingControlledInspectionClaimRecoverySourceIntegrityTest',
            'HousekeepingControlledInspectionClaimRecoveryWorkspaceTest',
            'HousekeepingControlledInspectionClaimRecoveryIsolatedConcurrencyProofTest',
        ], array_slice($baseline->classes, -6));

        $this->assertEquals(209, $baseline->expected->tests ?? null);
        $this->assertEquals(3793, $baseline->expected->assertions ?? null);
        $this->assertEquals(0, $baseline->expected->failures ?? null);
        $this->assertEquals(0, $baseline->expected->errors ?? null);
        $this->assertSame([], $baseline->accepted_debt ?? null);
        $this->assertEquals('a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50', $baseline->provenance->sha ?? null);
        $this->assertEquals('sprint-package-19-housekeeping-inspection-claim-recovery-reassignment', $baseline->provenance->branch ?? null);
        $this->assertEquals('2026-08-14', $baseline->provenance->measured_at ?? null);
        $this->assertSame('PACKAGE_19_INDEPENDENT_REVIEW_CORRECTED_RUNTIME_PROOF', $baseline->provenance->governance_status ?? null);
        $this->assertStringContainsString('Contract Version 1.20', $baseline->provenance->current_governance_note ?? '');
        $this->assertStringContainsString('a99f4b20489c3259c416297310a7b02f9cb6dacb', $baseline->provenance->current_governance_note ?? '');
        $this->assertMatchesRegularExpression('/a65736bab5f49c6ab9c39287f5ae01e7dd0b9a50.*3f05283dc878c9ec098ba0e27b319451abda36ad.*88750a9a23067d1630d0bf151510f0a94083f546/', $baseline->provenance->current_governance_note ?? '');
        $this->assertMatchesRegularExpression('/deterministic server ownership of occurred_at \/ created_at.*expanded direct PostgreSQL malformed-write proof.*no lifecycle redesign.*no Package 17 claim mutation/', $baseline->provenance->current_governance_note ?? '');
        $this->assertStringContainsString('209 tests / 3793 assertions / 0 failures / 0 errors', $baseline->provenance->current_governance_note ?? '');
        $this->assertStringContainsString('18 tests / 254 assertions', $baseline->provenance->current_governance_note ?? '');
        $this->assertStringContainsString('NO_NEW_ADR_REQUIRED', $baseline->provenance->current_governance_note ?? '');
        $this->assertStringContainsString('Package 18 governance-only synchronization runs under Contract Version 1.20', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Package 17 acceptance through PR #51 at canonical merge 37750626f9e0614d26d628a4707bcb205508ae03', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Package 18 governance commit 8cc177066dcd0598e740bea9a70ef756353d1442 and Housekeeping Contract-guard alignment commit df606720148a0a09df12eb111f5ddd79851608ed', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('without weakening any Package 17 runtime, PostgreSQL, claimant-ownership, maker-checker, Property-scope, no-background-runtime, no-assignment-aggregate, or ADR-ceiling assertion', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('No Housekeeping production source changed', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('accepted Package 17 runtime provenance remains preserved in note', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('exact 28-class Housekeeping baseline remains 191 tests / 3539 assertions / 0 failures / 0 errors', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('Package 19 runtime remains locked', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('No new ADR and no new accepted debt', $baseline->provenance->governance_note ?? '');
        $this->assertStringContainsString('28 exact classes / 191 tests / 3539 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('six Package 17 exact classes passed 27 tests / 770 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('post-deployment no-evidence pending-to-in_progress claim bypass and legacy-style post-cleaning INSERT closed', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('blocks historical supervisor takeover, and enforces historical terminal maker-checker', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('preserving pre-P17 rows without fabricated evidence and leaving v1 claim semantics unchanged', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Foundation alignment b45bba591e32963c2bbe7e03a82cc9f997a5d6c1 tests application legacy maker-checker with unsaved legacy-shaped models', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('P13 coordinator alignment 55399a7c53dc9c5f099ee4570ec1bc1bb6fd757b creates pending Inspection fixtures and uses the canonical Package 17 claim service', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('P13 migration isolation 98ccdeb9be1b9bc60b2df9cda2d31bbe9aed4a59 temporarily applies the canonical Package 17 down/up methods only inside its disposable predecessor proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('historical terminal rows survive successor reapply with NULL claim evidence', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No Package 13 production source changed, Front Desk remains 729 tests / 5657 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('accepted_debt remains empty, and no new accepted debt was added', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('six corrected Package 17 exact classes passed 27 tests / 675 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Historical Package 13 in_progress, passed, and failed Inspection rows remain unchanged with NULL Package 17 evidence and cannot be adopted as Package 17 claims', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('new claims require the initial in_progress state, immutable evidence, maker-checker segregation, and exact terminal replay', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('stale test coordinator fixture that assigned the same inspector as completed cleaner and supervisor', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('exact Package 13 isolated concurrency proof passed twice consecutively at 1 test / 93 assertions each', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('No assertion was weakened, no Package 13 production code changed, and no Package 18 or other scope was added', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Original Package 17 source commit 20112b623d04c50655e8701566c1dbd156e6dc53 measured 28 exact classes / 189 tests / 3139 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('original metadata commit de3e131c091f02fbb70cabb41006accecb0ce1bd remains retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('one Housekeeping-owned canonical post-cleaning Inspection claim writer', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('claimed_at / claim_idempotency_key / claim_source_hash / claim_evidence_version', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('cleaner/inspector maker-checker segregation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('claimant-owned pass/fail', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 16 canonical merge a8dd9ffdea4f09d1c223d6db9e43def756cc5682', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 15 provenance history is retained below', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('22 exact classes / 164 tests / 2733 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 15 exact classes passed 17 tests / 497 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('controlled initial assignment and controlled pre-start reassignment', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('exactly-one-active database enforcement', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('immutable assignment history', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('deterministic Property-scoped idempotency', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('current Property / attendant / Department validation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('bounded Package 12 dispatch/workload projection', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11/12/13 preserved', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 13 provenance history retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('18 exact classes / 147 tests / 2236 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 13 exact classes passed 31 tests / 334 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('resource-policy and readiness-permission rechecks', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('database-bound re-cleaning source graphs with 12 malformed-source cases', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('in-memory ambiguous-response recovery without credential persistence', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('current Package 11 focused batch passed 37 tests / 1402 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('canonical Cleaning Task and Room Inspection readiness orchestration boundary', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('sensitive release confirmation bound to exact evidence', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('pass-versus-fail', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('The frontend production build passed', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 12 provenance history retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('14 exact classes / 116 tests / 1902 assertions / 0 failures / 0 errors', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 12 exact classes passed 16 tests / 279 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('read-only checkout-turnover workspace', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('deterministic operational states', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('current-Property isolation', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('PostgreSQL wall-clock timing', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('zero mutation GET proof', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('PII and secret-field exclusion', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 runtime is unchanged', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no migration', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no ADR', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no scheduler', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no external integration', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no new accepted debt', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Inventory Reversal inherited debt is unchanged', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 provenance history retained', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('388d243c536964ce73ade3d3f30994070135cef2', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Package 11 focused batch passed 37 tests / 1399 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('UP/DOWN/REAPPLY', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('24 malformed direct-SQL cases', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('splits scenarios A-J into 10 separate tests', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('distinct PHP and PostgreSQL backend PIDs', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Scenario C proves a real worker exits after the Housekeeping transaction commits and before markDelivered', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('Scenario J proves replay has exact zero delta', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('P11_WORKER_INTERNAL_FAILURE', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('CleaningTask, Room/RoomService, and RoomInspection lifecycle regressions passed 46 tests / 109 assertions', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('CleaningTaskService has no runtime change', $baseline->provenance->note ?? '');
        $this->assertStringContainsString('no new accepted debt', $baseline->provenance->note ?? '');
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
                "Baseline '{$baseline->id}' status '{$baseline->status}' is not valid. Must be one of: ".implode(', ', $validStatuses).'.'
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
                "Baseline '{$baseline->id}' execution_mode '{$baseline->execution_mode}' is not valid. Must be one of: ".implode(', ', $validModes).'.'
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
                    "Baseline '{$baseline->id}' has ".count($baseline->classes)." class(es) but expected.tests is {$baseline->expected->tests}. Non-empty classes must produce non-zero tests."
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
            'Must have exactly 14 active baselines. Found: '.implode(', ', $activeIds)
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
            'Must have exactly 2 candidate baselines. Found: '.implode(', ', $candidateIds)
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
        $ids = array_map(fn ($b) => $b->id, $this->manifest->baselines);

        $expectedCount = 16;
        $this->assertCount(
            $expectedCount,
            $ids,
            "Manifest must contain exactly {$expectedCount} baselines. Found: ".implode(', ', $ids)
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
        $this->assertEquals(918, $baseline->expected->assertions ?? null);
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

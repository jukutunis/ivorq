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
        $requiredFields = ['id', 'description', 'type', 'configuration', 'selection_policy', 'classes', 'expected', 'status'];

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

        $expectedCount = 6;
        $this->assertCount(
            $expectedCount,
            $ids,
            "Manifest must contain exactly {$expectedCount} baselines. Found: " . implode(', ', $ids)
        );
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

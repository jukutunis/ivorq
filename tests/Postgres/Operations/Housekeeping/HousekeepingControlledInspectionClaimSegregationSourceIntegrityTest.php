<?php

namespace Tests\Postgres\Operations\Housekeeping;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class HousekeepingControlledInspectionClaimSegregationSourceIntegrityTest extends TestCase
{
    public function test_exactly_one_canonical_claim_writer_exists_and_compatibility_services_only_delegate(): void
    {
        $production = $this->productionSources();
        $writers = [];
        foreach ($production as $path => $source) {
            if (str_contains($source, "'claim_evidence_version' => self::EVIDENCE_VERSION")) {
                $writers[] = $path;
            }
        }

        $this->assertSame([
            'Modules/Operations/Housekeeping/Services/HousekeepingInspectionClaimService.php',
        ], $writers);

        $lifecycle = $production['Modules/Operations/Housekeeping/Services/HousekeepingCleaningInspectionReadinessLifecycleService.php'];
        $inspectionService = $production['Modules/Operations/Housekeeping/Services/InspectionService.php'];
        $this->assertStringContainsString('$this->inspectionClaim->claim(', $lifecycle);
        $this->assertStringContainsString('$this->claimService->claim(', $inspectionService);
        $this->assertStringNotContainsString("'supervisor_id' => \$actor->id", $lifecycle);
        $this->assertStringNotContainsString("'status' => InspectionStatusEnum::InProgress", $lifecycle);
    }

    public function test_browser_and_generic_crud_cannot_supply_claim_or_post_cleaning_authority(): void
    {
        $conduct = $this->source('Modules/Operations/Housekeeping/Http/Requests/ConductInspectionRequest.php');
        $store = $this->source('Modules/Operations/Housekeeping/Http/Requests/StoreRoomInspectionRequest.php');
        $update = $this->source('Modules/Operations/Housekeeping/Http/Requests/UpdateRoomInspectionRequest.php');
        $controller = $this->source('Modules/Operations/Housekeeping/Http/Controllers/RoomInspectionController.php');
        $claimService = $this->source('Modules/Operations/Housekeeping/Services/HousekeepingInspectionClaimService.php');

        $this->assertStringContainsString("'idempotency_key' => ['required'", $conduct);
        $this->assertStringContainsString("array_keys(\$this->all())", $conduct);
        foreach (['property_id', 'supervisor_id', 'claimed_at', 'claim_source_hash', 'claim_evidence_version'] as $authority) {
            $this->assertStringNotContainsString("'{$authority}' =>", $conduct);
            $this->assertStringContainsString("'{$authority}'", $update);
        }
        $this->assertStringContainsString("Rule::notIn(['post_cleaning'])", $store);
        $this->assertStringNotContainsString("'inspector_id'", $store);
        $this->assertStringNotContainsString("'supervisor_id' => auth()->id()", $controller);
        $this->assertStringContainsString('Post-cleaning Inspections are created only by the canonical cleaning-completion lifecycle.', $claimService = $this->source('Modules/Operations/Housekeeping/Services/InspectionService.php'));
        $this->assertStringNotContainsString('localStorage', $this->source('resources/js/Pages/Operations/Housekeeping/Inspections/Show.tsx'));
        $this->assertStringNotContainsString('sessionStorage', $this->source('resources/js/Pages/Operations/Housekeeping/Inspections/Show.tsx'));
    }

    public function test_current_property_scope_and_terminal_claim_ownership_are_explicit(): void
    {
        $repository = $this->source('Modules/Operations/Housekeeping/Repositories/InspectionRepository.php');
        $claim = $this->source('Modules/Operations/Housekeeping/Services/HousekeepingInspectionClaimService.php');
        $lifecycle = $this->source('Modules/Operations/Housekeeping/Services/HousekeepingCleaningInspectionReadinessLifecycleService.php');

        $this->assertGreaterThanOrEqual(5, substr_count($repository, "->where('property_id', \$this->propertyId())"));
        $this->assertStringContainsString('private function propertyId(): string', $repository);
        $this->assertStringContainsString('assertTerminalAuthority(', $claim);
        $this->assertStringContainsString('$actor->id !== $inspection->supervisor_id', $claim);
        $this->assertStringContainsString('$actor->id === $task->completed_by', $claim);
        $this->assertSame(4, substr_count($lifecycle, 'assertTerminalAuthority('));
        $this->assertStringContainsString('claimBoundTaskEvidence', $lifecycle);
        $this->assertStringContainsString('HousekeepingRoomReadinessTransitionService::RELEASE_INTENT', $lifecycle);
        $this->assertStringContainsString('$this->confirmation->confirm(', $lifecycle);
    }

    public function test_migration_enforces_property_scoped_idempotency_hash_relationship_and_immutability(): void
    {
        $migration = $this->source('Modules/Operations/Housekeeping/database/migrations/2026_08_11_000001_control_housekeeping_inspection_claims.php');

        foreach ([
            'hk_p17_inspection_claim_property_key_unique',
            'hk_p17_inspection_claim_evidence_check',
            'hk_p17_inspection_claim_source_hash',
            'hk_p17_inspection_claim_guard_trigger',
            'HK_P17_INSPECTION_CLAIM_CLEANER_PROHIBITED',
            'HK_P17_INSPECTION_CLAIM_IMMUTABLE',
            'HK_P17_INSPECTION_LEGACY_ADOPTION_PROHIBITED',
            'HK_P17_INSPECTION_CLAIM_INITIAL_STATUS_INVALID',
            'HK_P17_INSPECTION_LEGACY_STYLE_INSERT_PROHIBITED',
            'HK_P17_INSPECTION_CLAIM_BYPASS_PROHIBITED',
            'HK_P17_INSPECTION_LEGACY_SUPERVISOR_IMMUTABLE',
            'HK_P17_INSPECTION_LEGACY_TERMINAL_CLEANER_PROHIBITED',
            'housekeeping_task_assignments',
            'property_user',
        ] as $marker) {
            $this->assertStringContainsString($marker, $migration);
        }
        $this->assertStringContainsString('BEFORE INSERT OR UPDATE OR DELETE ON room_inspections', $migration);
        $this->assertStringContainsString("DROP FUNCTION IF EXISTS hk_p17_inspection_claim_source_hash", $migration);
    }

    public function test_package_adds_no_parallel_assignment_background_or_foreign_domain_runtime(): void
    {
        $newRuntime = implode("\n", [
            $this->source('Modules/Operations/Housekeeping/Services/HousekeepingInspectionClaimService.php'),
            $this->source('Modules/Operations/Housekeeping/ValueObjects/HousekeepingInspectionClaimResult.php'),
            $this->source('Modules/Operations/Housekeeping/database/migrations/2026_08_11_000001_control_housekeeping_inspection_claims.php'),
        ]);

        foreach (['Queue', 'ShouldQueue', 'dispatch(', 'schedule(', 'WebSocket', 'Kafka', 'RabbitMQ', 'Http::', 'Guzzle', 'Finance', 'GeneralCashier', 'Inventory', 'FrontDesk'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $newRuntime);
        }
        $this->assertStringNotContainsString('class InspectionAssignment', $newRuntime);
        $this->assertStringNotContainsString('claimed_by', $newRuntime);
        $this->assertStringNotContainsString('inspector_user_id', $newRuntime);
        $this->assertStringNotContainsString('Package 18', $newRuntime);

        $contract = $this->source('.agents/contracts/IVORQ-Package-Execution-Contract.md');
        $this->assertStringContainsString('Version: 1.22', $contract);
        $this->assertDirectoryExists(base_path('docs/architecture/adr'));
        foreach (glob(base_path('docs/architecture/adr/ADR-*.md')) ?: [] as $adr) {
            if (preg_match('/^ADR-(\d+)-/', basename($adr), $matches) === 1) {
                $this->assertLessThanOrEqual(89, (int) $matches[1], 'Package 17 must not introduce a new ADR.');
            }
        }
    }

    /** @return array<string, string> */
    private function productionSources(): array
    {
        $root = base_path('Modules/Operations/Housekeeping');
        $sources = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}

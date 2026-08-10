<?php

namespace Tests\Postgres\Operations\Housekeeping;

use PHPUnit\Framework\TestCase;

class HousekeepingCleaningInspectionReadinessSourceIntegrityTest extends TestCase
{
    public function test_legacy_services_contain_no_direct_room_readiness_assignment(): void
    {
        foreach ([
            'Modules/Operations/Housekeeping/Services/CleaningTaskService.php',
            'Modules/Operations/Housekeeping/Services/InspectionService.php',
        ] as $path) {
            $source = file_get_contents($this->path($path));
            $this->assertIsString($source);
            $this->assertStringNotContainsString("readiness_state =", $source);
            $this->assertStringNotContainsString("'readiness_state' =>", $source);
            $this->assertStringNotContainsString('changeCleanlinessStatus(', $source);
            $this->assertStringContainsString('HousekeepingCleaningInspectionReadinessLifecycleService', $source);
        }
    }

    public function test_pass_and_failure_operations_use_only_the_canonical_transition_authority(): void
    {
        $source = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingCleaningInspectionReadinessLifecycleService.php'));
        $transitionSource = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingRoomReadinessTransitionService.php'));

        $this->assertStringContainsString('$this->readiness->releaseReady(', $source);
        $this->assertStringContainsString('$this->readiness->inspectionFailed(', $source);
        $this->assertStringContainsString('SensitiveActionConfirmationService', $source);
        $this->assertStringContainsString('confirmationMetadataFor(', $transitionSource);
        $this->assertStringContainsString('DB::afterCommit(', $transitionSource);
        $this->assertStringContainsString('HousekeepingRoomReadinessTransition::create', $transitionSource);
        $this->assertStringNotContainsString("readiness_state' => 'ready_for_sale'", $source);
        $this->assertStringNotContainsString("readiness_state' => 'waiting_cleaning'", $source);
    }

    public function test_http_lifecycle_boundary_rejects_client_owned_authority_and_has_no_bypass_route(): void
    {
        $requests = implode("\n", array_map(
            fn (string $path): string => (string) file_get_contents($this->path($path)),
            [
                'Modules/Operations/Housekeeping/Http/Requests/ChangeCleaningTaskStatusRequest.php',
                'Modules/Operations/Housekeeping/Http/Requests/PassInspectionRequest.php',
                'Modules/Operations/Housekeeping/Http/Requests/FailInspectionRequest.php',
            ]
        ));
        $readinessController = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Controllers/HousekeepingRoomReadinessController.php'));
        $inspectionController = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Controllers/RoomInspectionController.php'));
        $routes = file_get_contents($this->path('Modules/Operations/Housekeeping/routes/web.php'));

        $this->assertStringContainsString('lifecycle authority parameter is not accepted', $requests);
        $this->assertStringNotContainsString("'property_id' => ['required'", $requests);
        $this->assertStringNotContainsString("'room_id' => ['required'", $requests);
        $this->assertStringNotContainsString("'source_type' =>", $readinessController);
        $this->assertStringNotContainsString("'source_id' =>", $readinessController);
        $this->assertStringContainsString('HOUSEKEEPING_LIFECYCLE_ACTION_FAILED', $inspectionController);
        $this->assertSame(1, substr_count($routes, "->name('release-ready')"));
        $this->assertStringNotContainsString('direct-release', $routes);
    }

    public function test_production_sources_add_no_scheduler_queue_external_http_or_cross_domain_write(): void
    {
        $source = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingCleaningInspectionReadinessLifecycleService.php'));

        foreach (['Http::', 'Guzzle', 'dispatch(', 'ShouldQueue', 'Schedule::', 'FrontDesk', 'GeneralCashier', 'NightAudit', 'PMS\\Models'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('Room -> CleaningTask -> active TaskAssignment -> RoomInspection', $source);
        $this->assertStringContainsString('CurrentPropertyService', $source);
        $this->assertStringContainsString("where('property_id', \$propertyId)", $source);
    }

    public function test_serialized_resources_and_confirmation_ui_exclude_sensitive_evidence(): void
    {
        $resource = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Resources/RoomInspectionResource.php'));
        $ui = file_get_contents($this->path('resources/js/Pages/Operations/Housekeeping/Inspections/Show.tsx'));
        $combined = strtolower($resource . "\n" . $ui);

        foreach (['source_hash', 'confirmation_hash', 'commercial_evidence_hash', 'guest_email', 'guest_phone', 'guest_name', 'sqlstate', 'stack_trace'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
        $this->assertStringContainsString('confirm room release', $combined);
        $this->assertStringContainsString('target_readiness', $combined);
        $this->assertStringContainsString('cleaning_task_code', $combined);
        $this->assertStringContainsString('current password', $combined);
    }

    public function test_package_11_and_package_12_consumers_remain_within_their_existing_boundaries(): void
    {
        $intake = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverIntakeService.php'));
        $workspace = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverWorkspaceQuery.php'));
        $workspaceController = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Controllers/HousekeepingCheckoutTurnoverWorkspaceController.php'));

        $this->assertStringNotContainsString('HousekeepingCleaningInspectionReadinessLifecycleService', $intake);
        $this->assertStringNotContainsString('HousekeepingCleaningInspectionReadinessLifecycleService', $workspace);
        $this->assertStringNotContainsString('HousekeepingCleaningInspectionReadinessLifecycleService', $workspaceController);
        $this->assertStringNotContainsString('->update(', $workspaceController);
        $this->assertStringNotContainsString('->delete(', $workspaceController);
        $this->assertStringNotContainsString('DB::transaction', $workspace);
    }

    public function test_migration_enforces_exact_source_outcomes_and_terminal_immutability(): void
    {
        $migration = file_get_contents($this->path('Modules/Operations/Housekeeping/database/migrations/2026_08_02_000001_integrate_housekeeping_cleaning_inspection_readiness.php'));

        foreach ([
            'hk_cleaning_tasks_rework_inspection_unique',
            'hk_room_inspections_post_cleaning_task_unique',
            'hk_cleaning_tasks_rework_property_fk',
            'hk_room_inspections_task_property_fk',
            'hk_room_inspections_lifecycle_guard_trigger',
            'hk_cleaning_tasks_lifecycle_guard_trigger',
            'INSPECTION_FAILED',
        ] as $required) {
            $this->assertStringContainsString($required, $migration);
        }
    }

    public function test_canonical_service_rechecks_policy_and_permission_and_compatibility_paths_cannot_impersonate(): void
    {
        $lifecycle = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingCleaningInspectionReadinessLifecycleService.php'));
        $taskService = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/CleaningTaskService.php'));
        $inspectionService = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/InspectionService.php'));
        $taskController = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Controllers/CleaningTaskController.php'));

        $this->assertGreaterThanOrEqual(2, substr_count($lifecycle, '$this->authorizeTask('));
        $this->assertGreaterThanOrEqual(2, substr_count($lifecycle, '$this->authorizeInspection('));
        $this->assertStringContainsString("can('changeStatus', \$task)", $lifecycle);
        $this->assertStringContainsString("can('conduct', \$inspection)", $lifecycle);
        $this->assertStringContainsString('HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED', $lifecycle);
        $this->assertStringNotContainsString('User::withoutGlobalScopes()->find', $taskService . $inspectionService);
        $this->assertStringContainsString('is_string($actorReference)', $taskService);
        $this->assertStringContainsString('is_string($actorReference)', $inspectionService);
        $this->assertStringContainsString('$request->user()', $taskController);
    }

    public function test_release_ui_retains_only_exact_in_memory_confirmation_for_ambiguous_retry(): void
    {
        $ui = file_get_contents($this->path('resources/js/Pages/Operations/Housekeeping/Inspections/Show.tsx'));

        $this->assertStringContainsString('confirmedRelease', $ui);
        $this->assertStringContainsString('evidenceKey', $ui);
        $this->assertStringContainsString('executionWasConfirmed && !requestError?.response', $ui);
        $this->assertStringContainsString("confirmation|expired|stale|evidence|mismatch|conflict", $ui);
        $this->assertStringContainsString('Retry Room Release', $ui);
        $this->assertStringContainsString("setPassword('')", $ui);
        $this->assertStringNotContainsString('localStorage', $ui);
        $this->assertStringNotContainsString('sessionStorage', $ui);
        $this->assertStringNotContainsString('document.cookie', $ui);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}

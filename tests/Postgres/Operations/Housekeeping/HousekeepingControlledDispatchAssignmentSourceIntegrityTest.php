<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Modules\Operations\Housekeeping\Services\HousekeepingTaskDispatchAssignmentService;
use Tests\TestCase;

class HousekeepingControlledDispatchAssignmentSourceIntegrityTest extends TestCase
{
    public function test_canonical_service_is_the_only_production_assignment_writer(): void
    {
        $root = $this->path('Modules/Operations/Housekeeping');
        $canonical = realpath($this->path('Modules/Operations/Housekeeping/Services/HousekeepingTaskDispatchAssignmentService.php'));
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === $canonical || str_contains($path, DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR) || str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $source = file_get_contents($path);
            foreach ([
                'TaskAssignment::create(',
                "DB::table('housekeeping_task_assignments')->insert",
                "DB::table('housekeeping_task_assignments')->update",
                '$assignment->update(',
            ] as $writer) {
                if (str_contains($source, $writer)) {
                    $violations[] = str_replace($root, '', $path) . ':' . $writer;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_legacy_services_controllers_and_requests_have_no_independent_authority(): void
    {
        $cleaningService = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/CleaningTaskService.php'));
        $assignmentService = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/TaskAssignmentService.php'));
        $cleaningController = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Controllers/CleaningTaskController.php'));
        $assignmentController = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Controllers/TaskAssignmentController.php'));
        $storeRequest = file_get_contents($this->path('Modules/Operations/Housekeeping/Http/Requests/StoreTaskAssignmentRequest.php'));

        $this->assertStringNotContainsString('function assign(', $cleaningService);
        foreach (['TaskAssignment::create', '->update(', '->delete(', 'DB::table'] as $writer) {
            $this->assertStringNotContainsString($writer, $assignmentService);
        }
        foreach ([$cleaningController, $assignmentController] as $controller) {
            $this->assertStringNotContainsString('TaskAssignment::create', $controller);
            $this->assertStringNotContainsString('$assignment->update', $controller);
        }
        foreach ([$storeRequest] as $request) {
            foreach (['property_id', 'company_id', 'room_id', 'assigned_by', 'assigned_at', 'closed_by', 'closed_at', 'source_hash', 'evidence_version', 'previous_assignment_id'] as $authority) {
                $this->assertStringNotContainsString("'{$authority}' =>", $request);
            }
            $this->assertStringNotContainsString('Rule::exists', $request);
        }
    }

    public function test_routes_expose_one_server_derived_assignment_action(): void
    {
        $routes = file_get_contents($this->path('Modules/Operations/Housekeeping/routes/web.php'));
        preg_match_all("/Route::post\('cleaning-tasks\/\{task\}\/(assign|reassign)'/", $routes, $matches);

        $this->assertSame(['assign'], $matches[1]);
        $this->assertStringNotContainsString("cleaning-tasks/{task}/reassign", $routes);
        $this->assertStringNotContainsString("assignments/{assignment}/complete", $routes);
        $this->assertStringNotContainsString("assignments/{assignment}/cancel", $routes);
        $this->assertStringNotContainsString("TaskAssignmentController::class, 'complete'", $routes);
        $this->assertStringNotContainsString("TaskAssignmentController::class, 'cancel'", $routes);
    }

    public function test_canonical_service_has_scoped_resolution_lock_order_and_bounded_evidence(): void
    {
        $service = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingTaskDispatchAssignmentService.php'));
        $enum = file_get_contents($this->path('Modules/Operations/Housekeeping/Enums/AssignmentStatusEnum.php'));

        $this->assertStringNotContainsString("case Reassigned = 'reassigned'", $enum);
        $this->assertStringContainsString('function assignOrReassign(', $service);
        $this->assertStringNotContainsString('function reassign(', $service);
        $this->assertStringContainsString('lockRoom(', $service);
        $this->assertStringContainsString('lockTask(', $service);
        $this->assertStringContainsString('lockActiveAssignments(', $service);
        $this->assertStringContainsString('lockTargetUser(', $service);
        $this->assertStringContainsString('lockMembership(', $service);
        $this->assertStringContainsString('lockDepartment(', $service);
        $this->assertStringContainsString('lockIdempotencyEvidence(', $service);
        foreach (['CleaningTask::find', 'User::find', 'Department::find', 'auth()->id', "source_hash' => \$newValues"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service);
        }
        $this->assertStringContainsString(HousekeepingTaskDispatchAssignmentService::NOT_AUTHORIZED, $service);
        $this->assertStringContainsString(HousekeepingTaskDispatchAssignmentService::IDEMPOTENCY_CONFLICT, $service);
    }

    public function test_workspace_and_workload_projection_are_read_only_privacy_minimized_and_in_memory_retry_only(): void
    {
        $query = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverWorkspaceQuery.php'));
        $workload = file_get_contents($this->path('Modules/Operations/Housekeeping/Services/HousekeepingAttendantWorkloadQuery.php'));
        $frontend = file_get_contents($this->path('resources/js/Pages/Operations/Housekeeping/CheckoutTurnovers/Index.tsx'));

        foreach (['->insert(', '->update(', '->delete(', '::create('] as $writer) {
            $this->assertStringNotContainsString($writer, $query);
            $this->assertStringNotContainsString($writer, $workload);
        }
        foreach (['email', 'phone', 'payroll', 'password', 'remember_token', 'source_hash', 'idempotency_key'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}'", $query);
            $this->assertStringNotContainsString("'{$forbidden}'", $workload);
        }
        $this->assertStringContainsString('window.crypto.randomUUID()', $frontend);
        $this->assertStringContainsString('!error.response', $frontend);
        $this->assertStringNotContainsString('localStorage', $frontend);
        $this->assertStringNotContainsString('sessionStorage', $frontend);
        $this->assertStringNotContainsString('WebSocket', $frontend);
    }

    public function test_package_adds_no_async_external_or_cross_domain_runtime(): void
    {
        $files = [
            'Modules/Operations/Housekeeping/Services/HousekeepingTaskDispatchAssignmentService.php',
            'Modules/Operations/Housekeeping/Services/HousekeepingAttendantWorkloadQuery.php',
            'Modules/Operations/Housekeeping/Http/Controllers/TaskAssignmentController.php',
        ];
        foreach ($files as $file) {
            $source = file_get_contents($this->path($file));
            foreach (['ShouldQueue', 'dispatch(', 'Http::', 'GuzzleHttp', 'WebSocket', 'schedule(', 'Modules\\Operations\\FrontDesk', 'Modules\\Operations\\Engineering'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}

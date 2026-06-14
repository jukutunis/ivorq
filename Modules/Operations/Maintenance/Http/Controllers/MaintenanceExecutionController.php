<?php

namespace Modules\Operations\Maintenance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Modules\Operations\Maintenance\Models\MaintenanceExecution;
use Modules\Operations\Maintenance\Services\MaintenanceExecutionService;
use Modules\Operations\Maintenance\DTOs\MaintenanceExecutionDTO;

class MaintenanceExecutionController extends Controller
{
    public function __construct(protected MaintenanceExecutionService $executionService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenanceExecution::class);
        $executions = MaintenanceExecution::where('property_id', app(\Shared\Services\CurrentPropertyService::class)->getPropertyId())
            ->with(['plan', 'asset'])
            ->cursorPaginate(100);

        return response()->json($executions);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('execute', MaintenanceExecution::class);
        $validated = $request->validate([
            'maintenance_plan_id' => 'required|string',
            'asset_id' => 'required|string',
            'status' => 'required|string',
            'scheduled_date' => 'required|date',
        ]);

        $dto = new MaintenanceExecutionDTO(
            property_id: app(\Shared\Services\CurrentPropertyService::class)->getPropertyId(),
            maintenance_plan_id: $validated['maintenance_plan_id'],
            asset_id: $validated['asset_id'],
            status: $validated['status'],
            scheduled_date: $validated['scheduled_date']
        );

        $execution = $this->executionService->generateExecution($dto);

        return response()->json($execution, 201);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $execution = MaintenanceExecution::findOrFail($id);
        $this->authorize('complete', $execution);
        
        $validated = $request->validate([
            'checklist_snapshot' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $execution = $this->executionService->completeExecution(
            $execution,
            $validated['checklist_snapshot'] ?? [],
            $request->user()->id,
            $validated['notes'] ?? null
        );

        return response()->json($execution);
    }
}

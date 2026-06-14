<?php

namespace Modules\Operations\Maintenance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Modules\Operations\Maintenance\Services\MaintenancePlanService;
use Modules\Operations\Maintenance\DTOs\MaintenancePlanDTO;

class MaintenancePlanController extends Controller
{
    public function __construct(protected MaintenancePlanService $planService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenancePlan::class);
        $plans = MaintenancePlan::where('property_id', app(\Shared\Services\CurrentPropertyService::class)->getPropertyId())
            ->with(['asset'])
            ->cursorPaginate(100);

        return response()->json($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MaintenancePlan::class);
        $validated = $request->validate([
            'asset_id' => 'required|string',
            'title' => 'required|string',
            'maintenance_type' => 'required|string',
            'status' => 'required|string',
            'frequency' => 'nullable|string',
            'next_due_date' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        $dto = new MaintenancePlanDTO(
            property_id: app(\Shared\Services\CurrentPropertyService::class)->getPropertyId(),
            asset_id: $validated['asset_id'],
            title: $validated['title'],
            maintenance_type: $validated['maintenance_type'],
            status: $validated['status'],
            description: $validated['description'] ?? null,
            frequency: $validated['frequency'] ?? null,
            next_due_date: $validated['next_due_date'] ?? null,
            created_by: $request->user()->id
        );

        $plan = $this->planService->createPlan($dto);

        return response()->json($plan, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $plan = MaintenancePlan::findOrFail($id);
        $this->authorize('view', $plan);
        return response()->json($plan->load(['asset', 'tasks', 'checklists']));
    }
}

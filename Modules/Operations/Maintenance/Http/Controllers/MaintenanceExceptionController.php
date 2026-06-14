<?php

namespace Modules\Operations\Maintenance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Modules\Operations\Maintenance\Services\MaintenanceExceptionService;
use Modules\Operations\Maintenance\DTOs\MaintenanceExceptionDTO;

class MaintenanceExceptionController extends Controller
{
    public function __construct(protected MaintenanceExceptionService $exceptionService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => 'required|string',
            'maintenance_plan_id' => 'nullable|string',
            'maintenance_execution_id' => 'nullable|string',
            'maintenance_checklist_id' => 'nullable|string',
            'exception_type' => 'required|string',
            'description' => 'nullable|string',
            'status' => 'required|string',
        ]);

        $dto = new MaintenanceExceptionDTO(
            property_id: app(\Shared\Services\CurrentPropertyService::class)->getPropertyId(),
            asset_id: $validated['asset_id'],
            exception_type: $validated['exception_type'],
            status: $validated['status'],
            maintenance_plan_id: $validated['maintenance_plan_id'] ?? null,
            maintenance_execution_id: $validated['maintenance_execution_id'] ?? null,
            maintenance_checklist_id: $validated['maintenance_checklist_id'] ?? null,
            description: $validated['description'] ?? null,
            reported_by: $request->user()->id
        );

        $exception = $this->exceptionService->logException($dto);

        return response()->json($exception, 201);
    }
}

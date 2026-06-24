<?php

namespace Modules\Operations\WorkOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Operations\WorkOrder\Services\WorkOrderClosureService;
use Modules\Operations\WorkOrder\DTOs\WorkOrderClosureDTO;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderClosureController extends Controller
{
    public function __construct(protected WorkOrderClosureService $service) {}

    public function store(Request $request, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('create', [\Modules\Operations\WorkOrder\Models\WorkOrderClosure::class, $workOrder]);

        $request->validate([
            'resolution_notes' => 'required|string',
        ]);

        $dto = WorkOrderClosureDTO::fromRequest($workOrder->id, $request);
        $closure = $this->service->close($dto, $request->user()->id);

        return response()->json($closure, 201);
    }
}

<?php

namespace Modules\Operations\WorkOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Operations\WorkOrder\Services\WorkOrderAssignmentService;
use Modules\Operations\WorkOrder\DTOs\WorkOrderAssignmentDTO;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderAssignmentController extends Controller
{
    public function __construct(protected WorkOrderAssignmentService $service) {}

    public function store(Request $request, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('assign', $workOrder);

        $request->validate([
            'user_id' => 'nullable|string',
            'department_id' => 'nullable|string',
        ]);

        $dto = WorkOrderAssignmentDTO::fromRequest($workOrder->id, $request);
        $assignment = $this->service->assign($dto, $request->user()->id);

        return response()->json($assignment, 201);
    }
}

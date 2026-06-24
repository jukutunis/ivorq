<?php

namespace Modules\Operations\WorkOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Operations\WorkOrder\Services\WorkOrderService;
use Modules\Operations\WorkOrder\DTOs\WorkOrderDTO;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

class WorkOrderController extends Controller
{
    public function __construct(protected WorkOrderService $service) {}

    public function index(Request $request)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $this->authorize('viewAny', WorkOrder::class);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $query = WorkOrder::where('property_id', $resolvedPropertyId);

        return response()->json($query->paginate());
    }

    public function store(Request $request)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $this->authorize('create', WorkOrder::class);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $dto = WorkOrderDTO::fromRequest($request);
        $wo = $this->service->create($dto, $request->user()->id);

        return response()->json($wo, 201);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('view', $workOrder);

        $workOrder->load(['tasks', 'assignments.user', 'labors', 'materials', 'approvals', 'closures', 'histories']);

        return response()->json($workOrder);
    }

    public function updateStatus(Request $request, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('update', $workOrder);

        $request->validate([
            'status' => 'required|string',
            'resolution_notes' => 'nullable|string',
        ]);

        $status = WorkOrderStatusEnum::from($request->input('status'));
        $resolutionNotes = $request->input('resolution_notes');

        $wo = $this->service->updateStatus($workOrder, $status, $request->user()->id, $resolutionNotes);

        return response()->json($wo);
    }
}

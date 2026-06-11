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
        $this->authorize('viewAny', WorkOrder::class);

        $query = WorkOrder::where('property_id', $request->header('X-Property-ID'));

        return response()->json($query->paginate());
    }

    public function store(Request $request)
    {
        $this->authorize('create', WorkOrder::class);

        $dto = WorkOrderDTO::fromRequest($request);
        $wo = $this->service->create($dto, $request->user()->id);

        return response()->json($wo, 201);
    }

    public function show(WorkOrder $workOrder)
    {
        $this->authorize('view', $workOrder);

        $workOrder->load(['tasks', 'assignments', 'labors', 'materials', 'approvals', 'closures', 'histories']);

        return response()->json($workOrder);
    }

    public function updateStatus(Request $request, WorkOrder $workOrder)
    {
        $this->authorize('update', $workOrder);

        $request->validate(['status' => 'required|string']);

        $status = WorkOrderStatusEnum::from($request->input('status'));
        $wo = $this->service->updateStatus($workOrder, $status, $request->user()->id);

        return response()->json($wo);
    }
}

<?php

namespace Modules\Operations\WorkOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Operations\WorkOrder\Services\WorkOrderLaborService;
use Modules\Operations\WorkOrder\DTOs\WorkOrderLaborDTO;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderLaborController extends Controller
{
    public function __construct(protected WorkOrderLaborService $service) {}

    public function store(Request $request, WorkOrder $workOrder)
    {
        $this->authorize('update', $workOrder);

        $request->validate([
            'status' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $dto = WorkOrderLaborDTO::fromRequest($workOrder->id, $request);
        $labor = $this->service->logTime($dto, $request->user()->id);

        return response()->json($labor, 201);
    }
}

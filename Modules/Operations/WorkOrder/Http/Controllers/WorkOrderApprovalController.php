<?php

namespace Modules\Operations\WorkOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Operations\WorkOrder\Services\WorkOrderApprovalService;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\Models\WorkOrderApproval;

class WorkOrderApprovalController extends Controller
{
    public function __construct(protected WorkOrderApprovalService $service) {}

    public function store(Request $request, WorkOrder $workOrder)
    {
        $this->authorize('update', $workOrder);

        $request->validate([
            'approver_id' => 'required|string',
            'mode' => 'required|string',
        ]);

        $approval = $this->service->requestApproval(
            $workOrder, 
            $request->input('approver_id'), 
            $request->input('mode'), 
            $request->user()->id
        );

        return response()->json($approval, 201);
    }

    public function update(Request $request, WorkOrderApproval $approval)
    {
        $this->authorize('update', $approval);

        $this->service->grantApproval($approval, $request->user()->id, $request->input('comments'));

        return response()->json($approval->fresh());
    }
}

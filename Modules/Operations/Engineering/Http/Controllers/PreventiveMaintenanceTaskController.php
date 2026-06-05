<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Http\Requests\ChangePreventiveMaintenanceTaskStatusRequest;
use Modules\Operations\Engineering\Http\Requests\StoreWorkOrderRequest;
use Modules\Operations\Engineering\Http\Resources\PreventiveMaintenanceTaskResource;
use Modules\Operations\Engineering\Http\Resources\WorkOrderResource;
use Modules\Operations\Engineering\Services\PreventiveMaintenanceTaskService;
use Shared\Services\CurrentPropertyService;

class PreventiveMaintenanceTaskController extends Controller
{
    public function __construct(
        private PreventiveMaintenanceTaskService $taskService,
    ) {}

    public function show(string $pm, string $task): Response
    {
        $model = $this->taskService->find($task);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Engineering/PreventiveMaintenanceTasks/Show', [
            'task' => new PreventiveMaintenanceTaskResource($model),
        ]);
    }

    public function changeStatus(ChangePreventiveMaintenanceTaskStatusRequest $request, string $pm, string $task): JsonResponse
    {
        $data    = $request->validated();
        $status  = PmTaskStatusEnum::from($data['status']);
        $remarks = $data['remarks'] ?? null;

        $updated = $this->taskService->changeStatus($task, $status, $remarks);

        return response()->json([
            'message' => "PM task status changed to {$status->label()}.",
            'task'    => new PreventiveMaintenanceTaskResource($updated),
        ]);
    }

    public function createWorkOrder(StoreWorkOrderRequest $request, string $pm, string $task): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $workOrder = $this->taskService->createWorkOrderFromTask($task, $data);

        return redirect()->route('operations.work-orders.show', $workOrder->id)
            ->with('success', 'Work order created from PM task successfully.');
    }
}

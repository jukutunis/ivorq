<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Http\Requests\ApproveWorkOrderRequest;
use Modules\Operations\Engineering\Http\Requests\AssignWorkOrderRequest;
use Modules\Operations\Engineering\Http\Requests\ChangeWorkOrderStatusRequest;
use Modules\Operations\Engineering\Http\Requests\StoreWorkOrderRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateWorkOrderRequest;
use Modules\Operations\Engineering\Http\Resources\WorkOrderResource;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Services\WorkOrderService;
use Shared\Services\CurrentPropertyService;

class WorkOrderController extends Controller
{
    public function __construct(
        private WorkOrderService $workOrderService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', WorkOrder::class);

        $filters    = request()->only(['status', 'work_order_type', 'priority', 'room_id', 'zone_id']);
        $workOrders = $this->workOrderService->paginate($filters);

        return Inertia::render('Operations/Engineering/WorkOrders/Index', [
            'work_orders' => WorkOrderResource::collection($workOrders),
            'statuses'    => array_map(
                fn(WorkOrderStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                WorkOrderStatusEnum::cases()
            ),
            'work_order_types' => array_map(
                fn(WorkOrderTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                WorkOrderTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(WorkOrderPriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                WorkOrderPriorityEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', WorkOrder::class);

        return Inertia::render('Operations/Engineering/WorkOrders/Create', [
            'work_order_types' => array_map(
                fn(WorkOrderTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                WorkOrderTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(WorkOrderPriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                WorkOrderPriorityEnum::cases()
            ),
        ]);
    }

    public function store(StoreWorkOrderRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $workOrder = $this->workOrderService->create($data);

        return redirect()->route('operations.work-orders.show', $workOrder->id)
            ->with('success', 'Work order created successfully.');
    }

    public function show(string $wo): Response
    {
        $model = $this->workOrderService->find($wo);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Engineering/WorkOrders/Show', [
            'work_order' => new WorkOrderResource($model),
        ]);
    }

    public function edit(string $wo): Response
    {
        $model = $this->workOrderService->find($wo);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Engineering/WorkOrders/Edit', [
            'work_order' => new WorkOrderResource($model),
            'work_order_types' => array_map(
                fn(WorkOrderTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                WorkOrderTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(WorkOrderPriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                WorkOrderPriorityEnum::cases()
            ),
            'statuses' => array_map(
                fn(WorkOrderStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                WorkOrderStatusEnum::cases()
            ),
        ]);
    }

    public function update(UpdateWorkOrderRequest $request, string $wo): RedirectResponse
    {
        $this->workOrderService->update($wo, $request->validated());

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Work order updated successfully.');
    }

    public function destroy(string $wo): RedirectResponse
    {
        $model = $this->workOrderService->find($wo);
        $this->authorize('delete', $model);

        $this->workOrderService->delete($wo);

        return redirect()->route('operations.work-orders.index')
            ->with('success', 'Work order deleted successfully.');
    }

    public function changeStatus(ChangeWorkOrderStatusRequest $request, string $wo): JsonResponse
    {
        $data    = $request->validated();
        $status  = WorkOrderStatusEnum::from($data['status']);
        $remarks = $data['remarks'] ?? null;

        $updated = $this->workOrderService->changeStatus($wo, $status, $remarks);

        return response()->json([
            'message'    => "Work order status changed to {$status->label()}.",
            'work_order' => new WorkOrderResource($updated),
        ]);
    }

    public function assign(AssignWorkOrderRequest $request, string $wo): RedirectResponse
    {
        $this->workOrderService->assign($wo, $request->validated());

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Technician assigned successfully.');
    }

    public function approve(ApproveWorkOrderRequest $request, string $wo): RedirectResponse
    {
        $this->workOrderService->approve($wo);

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Work order approved successfully.');
    }
}

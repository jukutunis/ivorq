<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Http\Requests\ApproveAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\CancelAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\RejectAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\StoreAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\SubmitAdjustmentRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateAdjustmentRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryAdjustmentResource;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Repositories\InventoryAdjustmentRepository;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Shared\Services\CurrentPropertyService;

class InventoryAdjustmentController extends Controller
{
    public function __construct(
        private AdjustmentService $adjustmentService,
        private InventoryAdjustmentRepository $adjustmentRepository,
        private InventoryMasterDataService $masterDataService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryAdjustment::class);

        $filters     = request()->only(['status', 'adjustment_type', 'location_id']);
        $adjustments = $this->adjustmentRepository->paginate($filters);

        return Inertia::render('Operations/Inventory/Adjustments/Index', [
            'adjustments'      => InventoryAdjustmentResource::collection($adjustments),
            'statuses'         => array_map(
                fn(AdjustmentStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                AdjustmentStatusEnum::cases()
            ),
            'adjustment_types' => array_map(
                fn(AdjustmentTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                AdjustmentTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryAdjustment::class);

        return Inertia::render('Operations/Inventory/Adjustments/Create', [
            'adjustment_types' => array_map(
                fn(AdjustmentTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                AdjustmentTypeEnum::cases()
            ),
            'items'     => $this->masterDataService->paginateItems([], 500)->items(),
            'locations' => $this->masterDataService->paginateLocations([], 500)->items(),
        ]);
    }

    public function store(StoreAdjustmentRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $adjustment = $this->adjustmentService->create($data);

        return redirect()->route('operations.inventory.adjustments.show', $adjustment->id)
            ->with('success', 'Adjustment created successfully.');
    }

    public function show(string $adjustment): Response
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Adjustments/Show', [
            'adjustment' => new InventoryAdjustmentResource($model),
        ]);
    }

    public function edit(string $adjustment): Response
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Adjustments/Edit', [
            'adjustment'       => new InventoryAdjustmentResource($model),
            'adjustment_types' => array_map(
                fn(AdjustmentTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                AdjustmentTypeEnum::cases()
            ),
            'items'     => $this->masterDataService->paginateItems([], 500)->items(),
            'locations' => $this->masterDataService->paginateLocations([], 500)->items(),
        ]);
    }

    public function update(UpdateAdjustmentRequest $request, string $adjustment): RedirectResponse
    {
        $this->adjustmentRepository->update($adjustment, $request->validated());

        return redirect()->route('operations.inventory.adjustments.show', $adjustment)
            ->with('success', 'Adjustment updated successfully.');
    }

    public function destroy(string $adjustment): RedirectResponse
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('delete', $model);

        $this->adjustmentRepository->delete($adjustment);

        return redirect()->route('operations.inventory.adjustments.index')
            ->with('success', 'Adjustment deleted successfully.');
    }

    public function submit(SubmitAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('submit', $model);

        $updated = $this->adjustmentService->submit($adjustment, auth()->id());

        return response()->json([
            'message'    => 'Adjustment submitted for approval.',
            'adjustment' => new InventoryAdjustmentResource($updated),
        ]);
    }

    public function approve(ApproveAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('approve', $model);

        $updated = $this->adjustmentService->approve($adjustment, auth()->id());

        return response()->json([
            'message'    => 'Adjustment approved and stock updated.',
            'adjustment' => new InventoryAdjustmentResource($updated),
        ]);
    }

    public function reject(RejectAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('reject', $model);

        $updated = $this->adjustmentRepository->update($adjustment, [
            'status'           => AdjustmentStatusEnum::Rejected->value,
            'rejected_by'      => auth()->id(),
            'rejected_at'      => now(),
            'rejection_reason' => $request->validated()['rejection_reason'],
        ]);

        return response()->json([
            'message'    => 'Adjustment rejected.',
            'adjustment' => new InventoryAdjustmentResource($updated),
        ]);
    }

    public function cancel(CancelAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->adjustmentRepository->find($adjustment);
        $this->authorize('cancel', $model);

        // Cancellation is not a service-level stock operation — just a status change.
        $updated = $this->adjustmentRepository->update($adjustment, [
            'status' => AdjustmentStatusEnum::Cancelled->value,
        ]);

        return response()->json([
            'message'    => 'Adjustment cancelled.',
            'adjustment' => new InventoryAdjustmentResource($updated),
        ]);
    }
}

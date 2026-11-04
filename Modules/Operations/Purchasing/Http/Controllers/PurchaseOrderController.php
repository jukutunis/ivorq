<?php

namespace Modules\Operations\Purchasing\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use Modules\Operations\Purchasing\Http\Requests\UpdatePurchaseOrderRequest;
use Modules\Operations\Purchasing\Http\Resources\PurchaseOrderResource;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Repositories\PurchaseOrderRepository;
use Modules\Operations\Purchasing\Services\PurchaseOrderService;

class PurchaseOrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected PurchaseOrderRepository $repository,
        protected PurchaseOrderService $service
    ) {
        $this->authorizeResource(PurchaseOrder::class, 'purchase_order');
    }

    public function index(Request $request): JsonResponse
    {
        $pos = $this->repository->paginate($request->all());

        return response()->json([
            'data' => PurchaseOrderResource::collection($pos),
            'meta' => [
                'current_page' => $pos->currentPage(),
                'last_page' => $pos->lastPage(),
                'per_page' => $pos->perPage(),
                'total' => $pos->total(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $po = $this->service->createFromApprovedPR(
            $data['purchase_request_id'],
            $data['vendor_id'],
            $data,
            $request->user()
        );

        return response()->json([
            'message' => 'Purchase Order created successfully',
            'data' => new PurchaseOrderResource($po),
        ], 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->repository->find($purchaseOrder->id);
        $po->loadMissing('revisionSnapshots');

        return response()->json([
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validated();
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        $po = $this->service->update($purchaseOrder->id, $data, $request->user(), $lines);

        return response()->json([
            'message' => 'Purchase Order updated successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('issue', $purchaseOrder);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        $po = $this->service->issue($purchaseOrder->id, $request->user(), $validated['idempotency_key']);

        return response()->json([
            'message' => 'Purchase Order issued successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('cancel', $purchaseOrder);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $po = $this->service->cancel($purchaseOrder->id, $request->user(), $validated['reason']);

        return response()->json([
            'message' => 'Purchase Order cancelled successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('submit', $purchaseOrder);

        $po = $this->service->submit($purchaseOrder->id, $request->user());

        return response()->json([
            'message' => 'Purchase Order submitted successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function revise(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('revise', $purchaseOrder);

        $po = $this->service->revise($purchaseOrder->id, $request->user());

        return response()->json([
            'message' => 'Purchase Order revision created successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('approve', $purchaseOrder);
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);
        $po = $this->service->approve($purchaseOrder->id, $request->user(), $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Purchase Order approved successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('reject', $purchaseOrder);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $po = $this->service->reject($purchaseOrder->id, $request->user(), $validated['reason']);

        return response()->json([
            'message' => 'Purchase Order rejected successfully',
            'data' => new PurchaseOrderResource($po),
        ]);
    }
}

<?php

namespace Modules\Operations\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Modules\Operations\Purchasing\Http\Requests\StorePurchaseRequestRequest;
use Modules\Operations\Purchasing\Http\Requests\UpdatePurchaseRequestRequest;
use Modules\Operations\Purchasing\Http\Resources\PurchaseRequestResource;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestRepository;
use Modules\Operations\Purchasing\Services\PurchaseRequestService;

class PurchaseRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected PurchaseRequestRepository $repository,
        protected PurchaseRequestService $service
    ) {
        $this->authorizeResource(PurchaseRequest::class, 'purchase_request');
    }

    public function index(Request $request): JsonResponse
    {
        $requests = $this->repository->paginate($request->all());

        return response()->json([
            'data' => PurchaseRequestResource::collection($requests),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['property_id'] = $request->user()->property_id;
        
        $lines = $data['lines'];
        unset($data['lines']);

        $pr = $this->service->createWithLines($data, $lines);

        return response()->json([
            'message' => 'Purchase Request created successfully',
            'data' => new PurchaseRequestResource($pr),
        ], 201);
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $purchaseRequest->load(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        return response()->json([
            'data' => new PurchaseRequestResource($purchaseRequest),
        ]);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $data = $request->validated();
        
        $lines = null;
        if (array_key_exists('lines', $data)) {
            $lines = $data['lines'];
            unset($data['lines']);
        }

        $updatedPr = $this->service->updateWithLines($purchaseRequest->id, $data, $lines);

        return response()->json([
            'message' => 'Purchase Request updated successfully',
            'data' => new PurchaseRequestResource($updatedPr),
        ]);
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->repository->delete($purchaseRequest->id);

        return response()->json([
            'message' => 'Purchase Request deleted successfully',
        ]);
    }

    public function cancel(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('cancel', $purchaseRequest);
        
        $updatedPr = $this->service->cancel($purchaseRequest->id);

        return response()->json([
            'message' => 'Purchase Request cancelled successfully',
            'data' => new PurchaseRequestResource($updatedPr),
        ]);
    }

    public function submit(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('submit', $purchaseRequest);

        $updatedPr = $this->service->submit($purchaseRequest->id);

        return response()->json([
            'message' => 'Purchase Request submitted successfully',
            'data' => new PurchaseRequestResource($updatedPr),
        ]);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('approve', $purchaseRequest);

        $remarks = $request->input('remarks');
        $updatedPr = $this->service->approve($purchaseRequest->id, $request->user(), $remarks);

        return response()->json([
            'message' => 'Purchase Request approved successfully',
            'data' => new PurchaseRequestResource($updatedPr),
        ]);
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('reject', $purchaseRequest);

        $remarks = $request->input('remarks');
        $updatedPr = $this->service->reject($purchaseRequest->id, $request->user(), $remarks);

        return response()->json([
            'message' => 'Purchase Request rejected successfully',
            'data' => new PurchaseRequestResource($updatedPr),
        ]);
    }
}

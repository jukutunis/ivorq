<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Operations\Inventory\Http\Requests\StoreInventoryReversalApprovalRequest;
use Modules\Operations\Inventory\Services\InventoryReversalApprovalRequestService;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalApprovalRequestIntent;

class InventoryReversalRequestController extends Controller
{
    public function __construct(
        private readonly InventoryReversalApprovalRequestService $requestService
    ) {}

    public function request(StoreInventoryReversalApprovalRequest $request): JsonResponse
    {
        $intent = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $request->validated('original_inventory_transaction_id'),
            actorId: auth()->id(),
            reversalReason: $request->validated('reversal_reason'),
            idempotencyKey: $request->validated('request_idempotency_key')
        );

        $result = $this->requestService->request($intent);

        return response()->json([
            'message' => 'Inventory reversal request processed successfully.',
            'outcome' => $result->outcome,
            'approval_request_id' => $result->approvalRequest->id,
            'status' => $result->approvalRequest->status,
        ]);
    }
}

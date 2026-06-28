<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Operations\Inventory\Http\Requests\ExecuteInventoryReversalRequest;
use Modules\Operations\Inventory\Services\InventoryReversalExecutionService;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalExecutionIntent;
use Modules\Foundation\Approval\Models\ApprovalRequest;

class InventoryReversalExecutionController extends Controller
{
    public function __construct(
        private readonly InventoryReversalExecutionService $executionService
    ) {}

    public function execute(ExecuteInventoryReversalRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $originalId = $approvalRequest->approvable_id;
        $reversalReason = $approvalRequest->notes['reversal_reason'] ?? '';

        if (empty($originalId) || empty($reversalReason)) {
            abort(422, 'Invalid approval evidence: original transaction ID or reversal reason is missing.');
        }

        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $originalId,
            actorId: auth()->id(),
            approvalReference: $approvalRequest->id,
            reversalReason: $reversalReason,
            idempotencyKey: $request->validated('execution_idempotency_key')
        );

        $result = $this->executionService->execute($intent);

        return response()->json([
            'message' => 'Inventory reversal executed successfully.',
            'outcome' => $result->outcome,
            'reversal_transaction_id' => $result->reversalTransaction->id,
        ]);
    }
}

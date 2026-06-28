<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Services\InventoryReversalCandidateGuard;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalApprovalRequestIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalApprovalRequestResult;
use Modules\Operations\Inventory\Exceptions\InventoryReversalApprovalRequestRejectedException;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Contracts\ApprovableContract;

class InventoryReversalApprovalRequestService
{
    public function __construct(
        private readonly InventoryReversalCandidateGuard $candidateGuard,
        private readonly ApprovalEngineService $approvalEngine
    ) {}

    public function request(InventoryReversalApprovalRequestIntent $intent): InventoryReversalApprovalRequestResult
    {
        return DB::transaction(function () use ($intent) {
            if (trim($intent->actorId) === '') {
                throw new InventoryReversalApprovalRequestRejectedException('missing_actor', 'Actor ID cannot be blank.');
            }
            if (trim($intent->reversalReason) === '') {
                throw new InventoryReversalApprovalRequestRejectedException('missing_reason', 'Reversal reason cannot be blank.');
            }
            if (trim($intent->idempotencyKey) === '') {
                throw new InventoryReversalApprovalRequestRejectedException('missing_request_idempotency_key', 'Request idempotency key cannot be blank.');
            }

            // Lock and validate the candidate original transaction
            $original = $this->candidateGuard->guard($intent->originalTransactionId);

            // Verify active reversal workflow exists for this property
            $approvableType = (new InventoryTransaction())->getMorphClass();
            $workflow = ApprovalWorkflow::where('approvable_type', $approvableType)
                ->where('property_id', $original->property_id)
                ->where('is_active', true)
                ->first();

            if (!$workflow) {
                throw new InventoryReversalApprovalRequestRejectedException(
                    'approval_workflow_unavailable',
                    'No active reversal approval workflow found for this property.'
                );
            }

            // Check request-level idempotency
            $existing = ApprovalRequest::where('property_id', $original->property_id)
                ->where('notes->request_idempotency_key', $intent->idempotencyKey)
                ->first();

            if ($existing) {
                // Prove equivalence
                $reason = $existing->notes['reversal_reason'] ?? '';
                if (
                    $existing->approvable_id !== $intent->originalTransactionId ||
                    $existing->requester_id !== $intent->actorId ||
                    $reason !== $intent->reversalReason
                ) {
                    throw new InventoryReversalApprovalRequestRejectedException(
                        'idempotency_conflict',
                        'Idempotency key is in use by a different request.'
                    );
                }

                return new InventoryReversalApprovalRequestResult(
                    outcome: 'replayed',
                    originalTransaction: $original,
                    approvalRequest: $existing,
                    idempotencyKey: $intent->idempotencyKey
                );
            }

            // Submit approval request via engine wrapper
            $wrapper = new InventoryReversalApprovalRequestWrapper($original);
            $approvalRequest = $this->approvalEngine->submitForApproval($wrapper, $intent->actorId);

            // Persist idempotency and reason evidence in the notes field
            $approvalRequest->update([
                'notes' => [
                    'request_idempotency_key' => $intent->idempotencyKey,
                    'reversal_reason' => $intent->reversalReason,
                    'original_transaction_id' => $intent->originalTransactionId,
                ]
            ]);

            return new InventoryReversalApprovalRequestResult(
                outcome: 'created',
                originalTransaction: $original,
                approvalRequest: $approvalRequest,
                idempotencyKey: $intent->idempotencyKey
            );
        });
    }
}

class InventoryReversalApprovalRequestWrapper implements ApprovableContract
{
    public function __construct(
        private readonly InventoryTransaction $transaction
    ) {}

    public function getApprovableType(): string
    {
        return (new InventoryTransaction())->getMorphClass();
    }

    public function getApprovableId(): string
    {
        return $this->transaction->id;
    }

    public function getPropertyId(): string
    {
        return $this->transaction->property_id;
    }

    public function getDepartmentId(): ?string
    {
        return null;
    }

    public function getApprovalAmount(): float
    {
        return 0.0;
    }

    public function markAsApproved(): void
    {
        // No-op
    }

    public function markAsRejected(?string $reason = null): void
    {
        // No-op
    }
}

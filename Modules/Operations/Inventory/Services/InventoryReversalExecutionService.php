<?php

namespace Modules\Operations\Inventory\Services;

use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\Services\InventoryReversalPostingService;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalExecutionIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalExecutionResult;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;
use Modules\Operations\Inventory\Exceptions\InventoryReversalExecutionRejectedException;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Foundation\Audit\Models\AuditLog;

class InventoryReversalExecutionService
{
    public function __construct(
        private readonly InventoryTransactionRepository $transactionRepo,
        private readonly InventoryReversalPostingService $postingService
    ) {}

    public function execute(InventoryReversalExecutionIntent $intent): InventoryReversalExecutionResult
    {
        if (trim($intent->actorId) === '') {
            throw new InventoryReversalExecutionRejectedException('missing_actor', 'Actor ID cannot be blank.');
        }

        if (trim($intent->approvalReference) === '') {
            throw new InventoryReversalExecutionRejectedException('missing_approval', 'Approval reference cannot be blank.');
        }

        if (trim($intent->reversalReason) === '') {
            throw new InventoryReversalExecutionRejectedException('missing_reason', 'Reversal reason cannot be blank.');
        }

        if (trim($intent->idempotencyKey) === '') {
            throw new InventoryReversalExecutionRejectedException('missing_idempotency_key', 'Idempotency key cannot be blank.');
        }

        $originalTx = $this->transactionRepo->findById($intent->originalTransactionId);
        if (!$originalTx) {
            throw new InventoryReversalExecutionRejectedException('approval_not_found', 'Original transaction not found.');
        }

        $approval = ApprovalRequest::find($intent->approvalReference);
        if (!$approval) {
            throw new InventoryReversalExecutionRejectedException('approval_not_found', 'Approval request not found.');
        }

        if ($approval->status !== 'Approved') {
            if (in_array($approval->status, ['Rejected', 'Cancelled', 'Expired', 'Revoked'])) {
                throw new InventoryReversalExecutionRejectedException('approval_not_executable', 'Approval is not executable.');
            }
            throw new InventoryReversalExecutionRejectedException('approval_not_finally_approved', 'Approval is not finally approved.');
        }

        $txMorphClass = (new InventoryTransaction())->getMorphClass();
        if ($approval->approvable_type !== $txMorphClass || $approval->approvable_id !== $intent->originalTransactionId) {
            throw new InventoryReversalExecutionRejectedException('approval_not_applicable', 'Approval is not applicable to the original transaction.');
        }

        // Check idempotency lookup
        $existing = $this->transactionRepo->findByIdempotency($originalTx->property_id, $intent->idempotencyKey);

        if ($existing) {
            // Prove equivalence
            if (
                $existing->reverses_inventory_transaction_id !== $intent->originalTransactionId ||
                $existing->valuation_approval_reference !== $intent->approvalReference ||
                $existing->posted_by !== $intent->actorId
            ) {
                throw new InventoryReversalExecutionRejectedException('idempotency_conflict', 'Idempotency key is in use by a different request.');
            }

            // Find existing ledger and audit details to reconstruct the result
            $costLedgerEntry = CostLedgerEntry::where('source_inventory_transaction_id', $existing->id)->first();
            $auditLog = AuditLog::where('auditable_type', get_class($existing))
                ->where('auditable_id', $existing->id)
                ->first();

            return new InventoryReversalExecutionResult(
                outcome: 'replayed',
                originalTransaction: $originalTx,
                reversalTransaction: $existing,
                approvalReference: $intent->approvalReference,
                idempotencyKey: $intent->idempotencyKey,
                costLedgerEntry: $costLedgerEntry,
                auditLog: $auditLog
            );
        }

        $postingIntent = new InventoryReversalPostingIntent(
            originalTransactionId: $intent->originalTransactionId,
            idempotencyKey: $intent->idempotencyKey,
            actorId: $intent->actorId,
            approvalReference: $intent->approvalReference,
            reversalReason: $intent->reversalReason
        );

        $postingResult = $this->postingService->post($postingIntent);

        return new InventoryReversalExecutionResult(
            outcome: 'posted',
            originalTransaction: $postingResult->originalTransaction,
            reversalTransaction: $postingResult->reversalTransaction,
            approvalReference: $intent->approvalReference,
            idempotencyKey: $intent->idempotencyKey,
            costLedgerEntry: $postingResult->costLedgerEntry,
            auditLog: $postingResult->auditLog
        );
    }
}

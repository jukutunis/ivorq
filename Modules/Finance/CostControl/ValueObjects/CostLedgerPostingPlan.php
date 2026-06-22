<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;

class CostLedgerPostingPlan
{
    public readonly CostLedgerPostingDecision $decision;
    public readonly AvcoValuationState $priorState;
    public readonly AvcoValuationState $resultingState;
    public readonly ?AvcoValuationResult $valuationResult;
    public readonly ?CostLedgerEntryIntent $intent;
    public readonly string $sourceTransactionReference;
    public readonly string $idempotencyKey;

    public function __construct(
        CostLedgerPostingDecision $decision,
        AvcoValuationState $priorState,
        AvcoValuationState $resultingState,
        ?AvcoValuationResult $valuationResult,
        ?CostLedgerEntryIntent $intent,
        string $sourceTransactionReference,
        string $idempotencyKey
    ) {
        if (in_array($decision->status, [CostLedgerPostingDecision::STATUS_PENDING, CostLedgerPostingDecision::STATUS_REJECTED, CostLedgerPostingDecision::STATUS_CORRECTION_REQUIRED], true)) {
            if ($intent !== null) throw new InvalidArgumentException("intent must be null for pending/rejected/correction_required");
            if ($resultingState !== $priorState) throw new InvalidArgumentException("resultingState must be exact same instance as priorState for pending/rejected/correction_required");
        }

        if ($decision->status === CostLedgerPostingDecision::STATUS_ALLOW) {
            if (empty($sourceTransactionReference)) throw new InvalidArgumentException("sourceTransactionReference must be non-empty for allow");
            if (empty($idempotencyKey)) throw new InvalidArgumentException("idempotencyKey must be non-empty for allow");
            
            if ($intent === null) {
                if ($decision->reasonCode !== 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL') {
                    throw new InvalidArgumentException("intent may be null only for SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL");
                }
            } else {
                if ($intent->propertyId !== $priorState->propertyId) throw new InvalidArgumentException("intent propertyId must match priorState propertyId");
                if ($intent->idempotencyKey !== $idempotencyKey) throw new InvalidArgumentException("intent idempotencyKey must match plan idempotencyKey");
            }
        }

        $this->decision = $decision;
        $this->priorState = $priorState;
        $this->resultingState = $resultingState;
        $this->valuationResult = $valuationResult;
        $this->intent = $intent;
        $this->sourceTransactionReference = $sourceTransactionReference;
        $this->idempotencyKey = $idempotencyKey;
    }
}
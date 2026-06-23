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
        if (trim($sourceTransactionReference) === '') throw new InvalidArgumentException("sourceTransactionReference cannot be blank");
        if (trim($idempotencyKey) === '') throw new InvalidArgumentException("idempotencyKey cannot be blank");

        if (in_array($decision->status, [CostLedgerPostingDecision::STATUS_PENDING, CostLedgerPostingDecision::STATUS_REJECTED, CostLedgerPostingDecision::STATUS_CORRECTION_REQUIRED], true)) {
            if ($intent !== null) throw new InvalidArgumentException("intent must be null for pending/rejected/correction_required");
            if ($resultingState !== $priorState) throw new InvalidArgumentException("resultingState must be exact same instance as priorState for pending/rejected/correction_required");
        }

        if ($decision->status === CostLedgerPostingDecision::STATUS_ALLOW) {
            if ($intent !== null) {
                if ($valuationResult === null) throw new InvalidArgumentException("allow plan with intent requires non-null valuationResult");
                if ($valuationResult->status !== 'final') throw new InvalidArgumentException("allow plan with intent requires final valuationResult");
                if ($resultingState !== $valuationResult->newState) throw new InvalidArgumentException("resultingState must be exact same instance as valuationResult->newState");
                if ($intent->propertyId !== $priorState->propertyId) throw new InvalidArgumentException("intent propertyId must match priorState propertyId");
                if ($intent->idempotencyKey !== $idempotencyKey) throw new InvalidArgumentException("intent idempotencyKey must match plan idempotencyKey");
            } else {
                if ($decision->reasonCode !== 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL') {
                    throw new InvalidArgumentException("allow + null intent + any reason other than SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL must throw InvalidArgumentException");
                }
                if ($valuationResult === null) throw new InvalidArgumentException("transfer-neutral allow plan requires non-null valuationResult");
                if ($valuationResult->status !== 'final') throw new InvalidArgumentException("transfer-neutral allow plan requires final valuationResult");
                if ($valuationResult->reasonCode !== 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL') throw new InvalidArgumentException("transfer-neutral valuationResult reasonCode must be SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL");
                if ($resultingState !== $valuationResult->newState) throw new InvalidArgumentException("resultingState must be exact same instance as valuationResult->newState");
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
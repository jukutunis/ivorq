<?php
namespace Modules\Finance\CostControl\ValueObjects;

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
        $this->decision = $decision;
        $this->priorState = $priorState;
        $this->resultingState = $resultingState;
        $this->valuationResult = $valuationResult;
        $this->intent = $intent;
        $this->sourceTransactionReference = $sourceTransactionReference;
        $this->idempotencyKey = $idempotencyKey;
    }
}
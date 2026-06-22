<?php
namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\ValueObjects\ApprovedInventoryEvidence;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingWindow;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingPlan;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingDecision;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;

class CostLedgerPostingPlanner
{
    private CostLedgerPostingGuard $guard;
    private AvcoValuationEngine $engine;

    public function __construct(CostLedgerPostingGuard $guard, AvcoValuationEngine $engine)
    {
        $this->guard = $guard;
        $this->engine = $engine;
    }

    public function plan(
        ApprovedInventoryEvidence $evidence,
        CostLedgerPostingWindow $window,
        AvcoValuationState $priorState
    ): CostLedgerPostingPlan {
        $decision = $this->guard->evaluate($evidence, $window, $priorState);
        
        if ($decision->status !== CostLedgerPostingDecision::STATUS_ALLOW) {
            return new CostLedgerPostingPlan(
                $decision,
                $priorState,
                $priorState,
                null,
                null,
                $evidence->sourceTransactionReference,
                $evidence->idempotencyKey
            );
        }

        $vSeq = new ValuationSequence(
            $evidence->propertyId,
            $evidence->itemId,
            $evidence->valuationScope,
            $evidence->sourceBusinessDate,
            $evidence->entrySequence
        );

        $input = new AvcoValuationInput(
            $evidence->sourceTransactionReference,
            $vSeq,
            $evidence->eventType,
            $evidence->quantityDelta,
            $evidence->approvedValuationBasis,
            $evidence->occurredAt,
            false,
            null,
            $evidence->originalBusinessDate,
            $evidence->transferContext
        );

        $result = $this->engine->evaluate($input, $priorState);

        if ($result->status === AvcoValuationResult::STATUS_PENDING || 
            $result->status === AvcoValuationResult::STATUS_REJECTED || 
            $result->status === AvcoValuationResult::STATUS_CORRECTION_REQUIRED) {
            
            $mappedDecision = new CostLedgerPostingDecision(
                $result->status,
                $result->reasonCode ?? 'AVCO_FAILURE',
                true
            );

            return new CostLedgerPostingPlan(
                $mappedDecision,
                $priorState,
                $priorState,
                $result,
                null,
                $evidence->sourceTransactionReference,
                $evidence->idempotencyKey
            );
        }

        // Final or Provisional
        // Map eventType to entryType explicitly as per migration
        // CHECK (entry_type IN ('receipt', 'issue', 'adjustment', 'transfer', 'correction', 'reversal'));
        $entryType = $evidence->eventType;
        $metadata = $evidence->metadata ?? [];
        
        if ($entryType === 'positive_adjustment' || $entryType === 'negative_adjustment') {
            $metadata['adjustment_direction'] = $entryType;
            $entryType = 'adjustment';
        }

        if ($result->status === AvcoValuationResult::STATUS_PROVISIONAL && $result->reasonCode === 'NEGATIVE_INVENTORY_PROVISIONAL') {
             $metadata['provisional_unresolved_qty'] = $result->unresolvedProvisionalQuantity ? $result->unresolvedProvisionalQuantity->getValue() : '0.0000';
             $metadata['provisional_relieved_value'] = $result->signedCarryingValueDelta ? $result->signedCarryingValueDelta->getValue() : '0.0000';
        }

        $intent = new CostLedgerEntryIntent(
            $evidence->propertyId,
            $evidence->sourceInventoryTransactionId,
            null,
            $entryType,
            $evidence->idempotencyKey,
            $evidence->entrySequence,
            $evidence->currencyCode,
            $evidence->quantityDelta,
            $result->newState->weightedAverageUnitCost ?? AvcoDecimal::zero(),
            $result->signedCarryingValueDelta ?? AvcoDecimal::zero(),
            $evidence->sourceBusinessDate,
            $evidence->occurredAt,
            $evidence->originalBusinessDate,
            $metadata
        );

        return new CostLedgerPostingPlan(
            $decision,
            $priorState,
            $result->newState,
            $result,
            $intent,
            $evidence->sourceTransactionReference,
            $evidence->idempotencyKey
        );
    }
}
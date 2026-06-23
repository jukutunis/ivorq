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

        if ($result->status === AvcoValuationResult::STATUS_PROVISIONAL && $result->reasonCode === 'NEGATIVE_INVENTORY_PROVISIONAL') {
            $mappedDecision = new CostLedgerPostingDecision(
                CostLedgerPostingDecision::STATUS_PENDING,
                'PROVISIONAL_VALUATION_REQUIRES_RESOLUTION',
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

        if ($result->status === AvcoValuationResult::STATUS_CORRECTION_REQUIRED) {
            if ($result->reasonCode === 'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS') {
                $mappedDecision = new CostLedgerPostingDecision(
                    CostLedgerPostingDecision::STATUS_PENDING,
                    'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS',
                    true
                );
            } else {
                $mappedDecision = new CostLedgerPostingDecision(
                    CostLedgerPostingDecision::STATUS_REJECTED,
                    'UNSUPPORTED_AVCO_CORRECTION_CONTEXT',
                    true
                );
            }
            return new CostLedgerPostingPlan($mappedDecision, $priorState, $priorState, $result, null, $evidence->sourceTransactionReference, $evidence->idempotencyKey);
        }

        if ($result->status === AvcoValuationResult::STATUS_PENDING || 
            $result->status === AvcoValuationResult::STATUS_REJECTED) {
            
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

        if ($result->reasonCode === 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL') {
            $mappedDecision = new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_ALLOW, 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', false);
            return new CostLedgerPostingPlan(
                $mappedDecision,
                $priorState,
                $result->newState,
                $result,
                null,
                $evidence->sourceTransactionReference,
                $evidence->idempotencyKey
            );
        }

        $entryType = $evidence->eventType;
        $metadata = $evidence->metadata ?? [];
        
        $unitCost = null;
        $valueDelta = $result->signedCarryingValueDelta;

        if ($entryType === 'receipt' || $entryType === 'positive_adjustment') {
            $unitCost = $evidence->approvedValuationBasis;
        } elseif ($entryType === 'issue' || $entryType === 'negative_adjustment') {
            $unitCost = $priorState->weightedAverageUnitCost;
        }
        
        if ($unitCost === null || $valueDelta === null) {
            $mappedDecision = new CostLedgerPostingDecision(
                CostLedgerPostingDecision::STATUS_PENDING,
                'UNREPRESENTABLE_EVENT_VALUATION',
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

        if ($entryType === 'positive_adjustment' || $entryType === 'negative_adjustment') {
            $metadata['adjustment_direction'] = $entryType;
            $entryType = 'adjustment';
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
            $unitCost,
            $valueDelta,
            $evidence->sourceBusinessDate,
            $evidence->occurredAt,
            $evidence->originalBusinessDate,
            $metadata
        );

        $decision = new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_ALLOW, 'ELIGIBLE', false);

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
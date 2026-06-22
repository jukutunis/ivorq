<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use InvalidArgumentException;

class AvcoValuationEngine
{
    public function evaluate(AvcoValuationInput $input, AvcoValuationState $priorState): AvcoValuationResult
    {
        if ($priorState->propertyId !== $input->sequence->propertyId ||
            $priorState->itemId !== $input->sequence->itemId ||
            $priorState->valuationScope !== $input->sequence->valuationScope) {
            return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SCOPE_MISMATCH');
        }

        if ($priorState->lastAppliedSequence !== null) {
            try {
                if ($input->sequence->compareTo($priorState->lastAppliedSequence) <= 0) {
                    return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'OUT_OF_ORDER_OR_DUPLICATE_SEQUENCE');
                }
            } catch (InvalidArgumentException $e) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SCOPE_MISMATCH');
            }
        }

        if ($input->isSourceFinancialPeriodClosed) {
            if (empty($input->currentOpenCorrectionPeriodId)) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'MISSING_CORRECTION_PERIOD_FOR_CLOSED_SOURCE');
            }
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_CORRECTION_REQUIRED,
                $priorState,
                null,
                'BACKDATED_EFFECT_CLOSED_PERIOD',
                null, null,
                $input->currentOpenCorrectionPeriodId,
                $input->transactionReference,
                $input->originalBusinessDate ?? $input->sequence->businessDate,
                true
            );
        }

        $newQuantity = $priorState->onHandQuantity->add($input->quantityDelta);

        if ($input->eventType === 'receipt' || $input->eventType === 'positive_adjustment') {
            if (!$input->quantityDelta->isPositive()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'QUANTITY_DELTA_MUST_BE_POSITIVE');
            }
            if ($priorState->unresolvedProvisionalQuantity->isPositive()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $priorState, null, 'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS');
            }
            if ($input->approvedValuationBasis === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, null, 'MISSING_APPROVED_VALUATION_BASIS');
            }
            $transactionValue = $input->quantityDelta->mul($input->approvedValuationBasis);
            $newCarryingValue = $priorState->carryingValue->add($transactionValue);
            $newAvco = $newQuantity->isPositive() ? $newCarryingValue->div($newQuantity) : AvcoDecimal::zero();
            
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState(
                    $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                    $newQuantity, $newAvco, $newCarryingValue, $input->sequence
                ),
                $transactionValue
            );
        }

        if ($input->eventType === 'issue' || $input->eventType === 'negative_adjustment') {
            if (!$input->quantityDelta->isNegative()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'QUANTITY_DELTA_MUST_BE_NEGATIVE');
            }
            if ($priorState->unresolvedProvisionalQuantity->isPositive()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $priorState, null, 'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS');
            }
            if ($priorState->weightedAverageUnitCost === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, null, 'MISSING_PREVAILING_CARRYING_COST');
            }

            $issueQty = $input->quantityDelta->abs();
            
            if ($priorState->onHandQuantity->compareTo($issueQty) < 0) {
                $availableQty = $priorState->onHandQuantity->isPositive() ? $priorState->onHandQuantity : AvcoDecimal::zero();
                $relievedValue = $availableQty->mul($priorState->weightedAverageUnitCost);
                
                $unresolvedQty = $issueQty->sub($availableQty);
                
                $newCarryingValue = $priorState->carryingValue->sub($relievedValue);
                if ($priorState->onHandQuantity->compareTo($issueQty) == 0 || $newCarryingValue->isNegative()) {
                    // Exhausted
                    $newCarryingValue = AvcoDecimal::zero();
                    $relievedValue = $priorState->carryingValue;
                }

                return new AvcoValuationResult(
                    AvcoValuationResult::STATUS_PROVISIONAL,
                    new AvcoValuationState(
                        $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                        $newQuantity, null, AvcoDecimal::zero(), $input->sequence, $unresolvedQty
                    ),
                    AvcoDecimal::zero()->sub($relievedValue),
                    'NEGATIVE_INVENTORY_PROVISIONAL',
                    $unresolvedQty
                );
            }

            if ($priorState->onHandQuantity->compareTo($issueQty) == 0) {
                $relievedValue = $priorState->carryingValue;
                $newCarryingValue = AvcoDecimal::zero();
                $newAvco = null;
            } else {
                $relievedValue = $issueQty->mul($priorState->weightedAverageUnitCost);
                $newCarryingValue = $priorState->carryingValue->sub($relievedValue);
                $newAvco = $priorState->weightedAverageUnitCost;
            }

            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState(
                    $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                    $newQuantity, $newAvco, $newCarryingValue, $input->sequence
                ),
                AvcoDecimal::zero()->sub($relievedValue)
            );
        }

        if ($input->eventType === 'transfer') {
             if ($input->transferContext === null) {
                 return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'MISSING_TRANSFER_CONTEXT');
             }
             $ctx = $input->transferContext;
             if ($ctx->sourcePropertyId === $priorState->propertyId &&
                 $ctx->sourceItemId === $priorState->itemId &&
                 $ctx->sourceValuationScope === $priorState->valuationScope) {
                 
                 return new AvcoValuationResult(
                     AvcoValuationResult::STATUS_FINAL,
                     new AvcoValuationState(
                         $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                         $priorState->onHandQuantity, $priorState->weightedAverageUnitCost, $priorState->carryingValue, $input->sequence, $priorState->unresolvedProvisionalQuantity
                     ),
                     AvcoDecimal::zero(),
                     'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL',
                     null,
                     $ctx->sourceCarryingUnitCost
                 );
             }

             return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'TRANSFER_REQUIRES_PAIRED_SCOPE_MODEL');
        }
        
        return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'UNKNOWN_EVENT_TYPE');
    }
}

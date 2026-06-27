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

        $expectedLedgerSequence = $priorState->lastAppliedSequence !== null
            ? $priorState->lastAppliedSequence->ledgerSequence + 1
            : 1;

        if ($input->sequence->ledgerSequence !== $expectedLedgerSequence) {
            if ($priorState->lastAppliedSequence !== null && $input->sequence->ledgerSequence <= $priorState->lastAppliedSequence->ledgerSequence) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'OUT_OF_ORDER_OR_DUPLICATE_SEQUENCE');
            }
            return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SEQUENCE_GAP_DETECTED');
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

        if ($priorState->unresolvedProvisionalQuantity->isPositive()) {
            return new AvcoValuationResult(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $priorState, null, 'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS');
        }

        $newQuantity = $priorState->onHandQuantity->add($input->quantityDelta);

        if ($input->eventType === 'receipt' || $input->eventType === 'positive_adjustment') {
            if (!$input->quantityDelta->isPositive()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'QUANTITY_DELTA_MUST_BE_POSITIVE');
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
            if ($priorState->weightedAverageUnitCost === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, null, 'MISSING_PREVAILING_CARRYING_COST');
            }

            $issueQty = $input->quantityDelta->abs();
            
            if ($priorState->onHandQuantity->compareTo($issueQty) < 0) {
                $availableQty = $priorState->onHandQuantity->isPositive() ? $priorState->onHandQuantity : AvcoDecimal::zero();
                $relievedValue = $priorState->carryingValue; 
                
                $unresolvedQty = $issueQty->sub($availableQty);

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
            if ($input->quantityDelta->isZero()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'QUANTITY_DELTA_CANNOT_BE_ZERO');
            }
            if ($input->transferContext === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'MISSING_TRANSFER_CONTEXT');
            }
            $ctx = $input->transferContext;

            if ($ctx->destinationValuationScope === null) {
                // Legacy mode: same-scope check uses priorState comparison
                if ($ctx->sourcePropertyId === $priorState->propertyId &&
                    $ctx->sourceItemId === $priorState->itemId &&
                    $ctx->sourceValuationScope === $priorState->valuationScope) {
                    return $this->sameScopeNeutralResult($priorState, $input->sequence, $ctx->sourceCarryingUnitCost);
                }
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'TRANSFER_REQUIRES_PAIRED_SCOPE_MODEL');
            }

            // Paired-scope mode (destinationValuationScope is provided)
            if ($ctx->sourceValuationScope === $ctx->destinationValuationScope) {
                // Same source and destination scope — neutral transfer
                if ($ctx->sourcePropertyId === $priorState->propertyId &&
                    $ctx->sourceItemId === $priorState->itemId &&
                    $priorState->valuationScope === $ctx->sourceValuationScope) {
                    return $this->sameScopeNeutralResult($priorState, $input->sequence, $ctx->sourceCarryingUnitCost);
                }
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SCOPE_MISMATCH');
            }

            // Cross-scope: source and destination are distinct scopes
            if ($ctx->sourcePropertyId !== $priorState->propertyId || $ctx->sourceItemId !== $priorState->itemId) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SCOPE_MISMATCH');
            }

            $isSourceLeg      = ($priorState->valuationScope === $ctx->sourceValuationScope);
            $isDestinationLeg = ($priorState->valuationScope === $ctx->destinationValuationScope);

            if (!$isSourceLeg && !$isDestinationLeg) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'INVALID_CROSS_SCOPE_TRANSFER_LEG');
            }

            if ($isSourceLeg) {
                if (!$input->quantityDelta->isNegative()) {
                    return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'INVALID_CROSS_SCOPE_TRANSFER_LEG');
                }
                return $this->evaluateCrossScopeTransferOut($input, $priorState);
            }

            // $isDestinationLeg
            if (!$input->quantityDelta->isPositive()) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'INVALID_CROSS_SCOPE_TRANSFER_LEG');
            }
            return $this->evaluateCrossScopeTransferIn($input, $priorState, $ctx);
        }
        
        return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'UNKNOWN_EVENT_TYPE');
    }

    private function sameScopeNeutralResult(
        AvcoValuationState $priorState,
        \Modules\Finance\CostControl\ValueObjects\ValuationSequence $sequence,
        AvcoDecimal $sourceCarryingUnitCost
    ): AvcoValuationResult {
        return new AvcoValuationResult(
            AvcoValuationResult::STATUS_FINAL,
            new AvcoValuationState(
                $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                $priorState->onHandQuantity, $priorState->weightedAverageUnitCost,
                $priorState->carryingValue, $sequence, $priorState->unresolvedProvisionalQuantity
            ),
            AvcoDecimal::zero(),
            'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL',
            null,
            $sourceCarryingUnitCost
        );
    }

    private function evaluateCrossScopeTransferOut(
        AvcoValuationInput $input,
        AvcoValuationState $priorState
    ): AvcoValuationResult {
        if ($priorState->weightedAverageUnitCost === null) {
            return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'MISSING_PREVAILING_CARRYING_COST');
        }

        $issueQty     = $input->quantityDelta->abs();
        $newQuantity  = $priorState->onHandQuantity->add($input->quantityDelta);

        if ($newQuantity->isNegative()) {
            return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'CROSS_SCOPE_TRANSFER_OUT_EXCEEDS_AVAILABLE_QUANTITY');
        }

        if ($newQuantity->isZero()) {
            $relievedValue    = $priorState->carryingValue;
            $newCarryingValue = AvcoDecimal::zero();
            $newAvco          = null;
        } else {
            $relievedValue    = $issueQty->mul($priorState->weightedAverageUnitCost);
            $newCarryingValue = $priorState->carryingValue->sub($relievedValue);
            $newAvco          = $priorState->weightedAverageUnitCost;
        }

        return new AvcoValuationResult(
            AvcoValuationResult::STATUS_FINAL,
            new AvcoValuationState(
                $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                $newQuantity, $newAvco, $newCarryingValue, $input->sequence
            ),
            AvcoDecimal::zero()->sub($relievedValue),
            'CROSS_SCOPE_TRANSFER_OUT',
            null,
            $priorState->weightedAverageUnitCost
        );
    }

    private function evaluateCrossScopeTransferIn(
        AvcoValuationInput $input,
        AvcoValuationState $priorState,
        \Modules\Finance\CostControl\ValueObjects\TransferValuationContext $ctx
    ): AvcoValuationResult {
        $inValue          = $input->quantityDelta->mul($ctx->sourceCarryingUnitCost);
        $newQuantity      = $priorState->onHandQuantity->add($input->quantityDelta);
        $newCarryingValue = $priorState->carryingValue->add($inValue);
        $newAvco          = $newQuantity->isPositive()
            ? $newCarryingValue->div($newQuantity)
            : AvcoDecimal::zero();

        return new AvcoValuationResult(
            AvcoValuationResult::STATUS_FINAL,
            new AvcoValuationState(
                $priorState->propertyId, $priorState->itemId, $priorState->valuationScope,
                $newQuantity, $newAvco, $newCarryingValue, $input->sequence
            ),
            $inValue,
            'CROSS_SCOPE_TRANSFER_IN',
            null,
            $ctx->sourceCarryingUnitCost
        );
    }
}

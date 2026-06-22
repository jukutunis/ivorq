<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;
use InvalidArgumentException;

class AvcoValuationEngine
{
    public function evaluate(AvcoValuationInput $input, AvcoValuationState $priorState): AvcoValuationResult
    {
        if ($priorState->lastAppliedSequence !== null) {
            try {
                if ($input->sequence->compareTo($priorState->lastAppliedSequence) <= 0) {
                    return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'OUT_OF_ORDER_OR_DUPLICATE_SEQUENCE');
                }
            } catch (InvalidArgumentException $e) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SCOPE_MISMATCH');
            }
        } elseif ($priorState->valuationScope !== $input->sequence->valuationScope) {
            return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'SCOPE_MISMATCH');
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
                $input->originalBusinessDate ?? $input->sequence->businessDate
            );
        }

        if (abs($priorState->unresolvedProvisionalQuantity) > 0.0) {
            if ($input->eventType === 'receipt' || ($input->eventType === 'adjustment' && $input->quantityDelta > 0)) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, null, 'PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS');
            }
        }

        $newQuantity = $priorState->onHandQuantity + $input->quantityDelta;

        if ($input->eventType === 'receipt') {
            if ($input->approvedValuationBasis === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, null, 'MISSING_APPROVED_VALUATION_BASIS');
            }
            $transactionValue = $input->quantityDelta * $input->approvedValuationBasis;
            $newCarryingValue = $priorState->carryingValue + $transactionValue;
            
            $newAvco = $newQuantity > 0 ? $newCarryingValue / $newQuantity : 0.0;
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState($newQuantity, $newAvco, $newCarryingValue, $priorState->valuationScope, $input->sequence),
                $transactionValue
            );
        }

        if ($input->eventType === 'issue' || ($input->eventType === 'adjustment' && $input->quantityDelta < 0)) {
            $prevailingCost = $priorState->weightedAverageUnitCost ?? 0.0;
            $issueQty = abs($input->quantityDelta);
            
            if ($priorState->onHandQuantity < $issueQty) {
                $availableQty = max(0.0, $priorState->onHandQuantity);
                $transactionValue = $availableQty * $prevailingCost;
                $newCarryingValue = $priorState->carryingValue - $transactionValue;
                $unresolvedQty = $issueQty - $availableQty;
                $totalUnresolved = $priorState->unresolvedProvisionalQuantity + $unresolvedQty;

                return new AvcoValuationResult(
                    AvcoValuationResult::STATUS_PROVISIONAL,
                    new AvcoValuationState($newQuantity, $priorState->weightedAverageUnitCost, $newCarryingValue, $priorState->valuationScope, $input->sequence, $totalUnresolved),
                    $transactionValue,
                    'NEGATIVE_INVENTORY_PROVISIONAL',
                    $totalUnresolved
                );
            }

            $transactionValue = $issueQty * $prevailingCost;
            $newCarryingValue = $priorState->carryingValue - $transactionValue;
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState($newQuantity, $prevailingCost, $newCarryingValue, $priorState->valuationScope, $input->sequence),
                $transactionValue
            );
        }

        if ($input->eventType === 'adjustment' && $input->quantityDelta > 0) {
            if ($input->approvedValuationBasis === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, null, 'MISSING_APPROVED_VALUATION_BASIS');
            }
            $transactionValue = $input->quantityDelta * $input->approvedValuationBasis;
            $newCarryingValue = $priorState->carryingValue + $transactionValue;
            $newAvco = $newQuantity > 0 ? $newCarryingValue / $newQuantity : 0.0;
            
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState($newQuantity, $newAvco, $newCarryingValue, $priorState->valuationScope, $input->sequence),
                $transactionValue
            );
        }

        if ($input->eventType === 'transfer') {
             if ($input->sourceCarryingUnitCost === null) {
                 return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'MISSING_SOURCE_CARRYING_COST');
             }

             $transactionValue = abs($input->quantityDelta) * $input->sourceCarryingUnitCost;
             $signedTransactionValue = $input->quantityDelta > 0 ? $transactionValue : -$transactionValue;
             
             $newCarryingValue = $priorState->carryingValue + $signedTransactionValue;
             $prevailingCost = $priorState->weightedAverageUnitCost;

             if ($input->quantityDelta < 0 && $priorState->onHandQuantity < abs($input->quantityDelta)) {
                  $availableQty = max(0.0, $priorState->onHandQuantity);
                  $relievedValue = $availableQty * $input->sourceCarryingUnitCost;
                  $newCarryingValue = $priorState->carryingValue - $relievedValue;
                  $unresolvedQty = abs($input->quantityDelta) - $availableQty;
                  $totalUnresolved = $priorState->unresolvedProvisionalQuantity + $unresolvedQty;

                  return new AvcoValuationResult(
                      AvcoValuationResult::STATUS_PROVISIONAL,
                      new AvcoValuationState($newQuantity, $prevailingCost, $newCarryingValue, $priorState->valuationScope, $input->sequence, $totalUnresolved),
                      $relievedValue,
                      'TRANSFER_SHORTAGE_PROVISIONAL',
                      $totalUnresolved,
                      $input->sourceCarryingUnitCost
                  );
             }
             
             return new AvcoValuationResult(
                 AvcoValuationResult::STATUS_FINAL,
                 new AvcoValuationState($newQuantity, $prevailingCost, $newCarryingValue, $priorState->valuationScope, $input->sequence),
                 $signedTransactionValue,
                 null, null,
                 $input->sourceCarryingUnitCost
             );
        }
        
        return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, null, 'UNKNOWN_EVENT_TYPE');
    }
}

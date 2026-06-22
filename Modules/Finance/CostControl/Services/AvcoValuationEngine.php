<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;

class AvcoValuationEngine
{
    public function evaluate(AvcoValuationInput $input, AvcoValuationState $priorState): AvcoValuationResult
    {
        if ($input->isSourceFinancialPeriodClosed) {
            return new AvcoValuationResult(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $priorState, 0.0);
        }

        $newQuantity = $priorState->onHandQuantity + $input->quantityDelta;
        
        if ($input->eventType === 'receipt') {
            if ($input->approvedValuationBasis === null) {
                return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, 0.0);
            }
            $transactionValue = $input->quantityDelta * $input->approvedValuationBasis;
            $newCarryingValue = $priorState->carryingValue + $transactionValue;
            
            if ($newQuantity < 0) {
                 return new AvcoValuationResult(
                     AvcoValuationResult::STATUS_PROVISIONAL,
                     new AvcoValuationState($newQuantity, null, $newCarryingValue, $priorState->valuationScope),
                     $transactionValue
                 );
            }
            
            $newAvco = $newQuantity > 0 ? $newCarryingValue / $newQuantity : 0.0;
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState($newQuantity, $newAvco, $newCarryingValue, $priorState->valuationScope),
                $transactionValue
            );
        }
        
        if ($input->eventType === 'issue') {
            $prevailingCost = $priorState->weightedAverageUnitCost ?? 0.0;
            $transactionValue = abs($input->quantityDelta) * $prevailingCost;
            $newCarryingValue = $priorState->carryingValue - $transactionValue;
            
            if ($newQuantity < 0) {
                 return new AvcoValuationResult(
                     AvcoValuationResult::STATUS_PROVISIONAL,
                     new AvcoValuationState($newQuantity, null, $newCarryingValue, $priorState->valuationScope),
                     $transactionValue
                 );
            }
            return new AvcoValuationResult(
                AvcoValuationResult::STATUS_FINAL,
                new AvcoValuationState($newQuantity, $prevailingCost, $newCarryingValue, $priorState->valuationScope),
                $transactionValue
            );
        }
        
        if ($input->eventType === 'adjustment') {
            if ($input->quantityDelta > 0) {
                if ($input->approvedValuationBasis === null) {
                    return new AvcoValuationResult(AvcoValuationResult::STATUS_PENDING, $priorState, 0.0);
                }
                $transactionValue = $input->quantityDelta * $input->approvedValuationBasis;
                $newCarryingValue = $priorState->carryingValue + $transactionValue;
                $newAvco = $newQuantity > 0 ? $newCarryingValue / $newQuantity : 0.0;
                
                if ($newQuantity < 0) {
                    return new AvcoValuationResult(
                        AvcoValuationResult::STATUS_PROVISIONAL,
                        new AvcoValuationState($newQuantity, null, $newCarryingValue, $priorState->valuationScope),
                        $transactionValue
                    );
                }
                return new AvcoValuationResult(
                    AvcoValuationResult::STATUS_FINAL,
                    new AvcoValuationState($newQuantity, $newAvco, $newCarryingValue, $priorState->valuationScope),
                    $transactionValue
                );
            } else {
                 $prevailingCost = $priorState->weightedAverageUnitCost ?? 0.0;
                 $transactionValue = abs($input->quantityDelta) * $prevailingCost;
                 $newCarryingValue = $priorState->carryingValue - $transactionValue;
                 if ($newQuantity < 0) {
                     return new AvcoValuationResult(
                         AvcoValuationResult::STATUS_PROVISIONAL,
                         new AvcoValuationState($newQuantity, null, $newCarryingValue, $priorState->valuationScope),
                         $transactionValue
                     );
                 }
                 return new AvcoValuationResult(
                     AvcoValuationResult::STATUS_FINAL,
                     new AvcoValuationState($newQuantity, $prevailingCost, $newCarryingValue, $priorState->valuationScope),
                     $transactionValue
                 );
            }
        }
        
        if ($input->eventType === 'transfer') {
             $prevailingCost = $priorState->weightedAverageUnitCost ?? 0.0;
             $transactionValue = abs($input->quantityDelta) * $prevailingCost;
             
             $newCarryingValue = $priorState->carryingValue + ($input->quantityDelta > 0 ? $transactionValue : -$transactionValue);
             
             if ($newQuantity < 0) {
                  return new AvcoValuationResult(
                      AvcoValuationResult::STATUS_PROVISIONAL,
                      new AvcoValuationState($newQuantity, null, $newCarryingValue, $priorState->valuationScope),
                      $transactionValue
                  );
             }
             return new AvcoValuationResult(
                 AvcoValuationResult::STATUS_FINAL,
                 new AvcoValuationState($newQuantity, $prevailingCost, $newCarryingValue, $priorState->valuationScope),
                 $transactionValue
             );
        }
        
        return new AvcoValuationResult(AvcoValuationResult::STATUS_REJECTED, $priorState, 0.0);
    }
}

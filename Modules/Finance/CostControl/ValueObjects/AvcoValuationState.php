<?php

namespace Modules\Finance\CostControl\ValueObjects;

class AvcoValuationState
{
    public readonly float $onHandQuantity;
    public readonly ?float $weightedAverageUnitCost;
    public readonly float $carryingValue;
    public readonly string $valuationScope;
    public readonly ?ValuationSequence $lastAppliedSequence;
    public readonly float $unresolvedProvisionalQuantity;

    public function __construct(
        float $onHandQuantity,
        ?float $weightedAverageUnitCost,
        float $carryingValue,
        string $valuationScope,
        ?ValuationSequence $lastAppliedSequence = null,
        float $unresolvedProvisionalQuantity = 0.0
    ) {
        $this->onHandQuantity = $onHandQuantity;
        $this->weightedAverageUnitCost = $weightedAverageUnitCost;
        $this->carryingValue = $carryingValue;
        $this->valuationScope = $valuationScope;
        $this->lastAppliedSequence = $lastAppliedSequence;
        $this->unresolvedProvisionalQuantity = $unresolvedProvisionalQuantity;
    }
}

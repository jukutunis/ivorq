<?php

namespace Modules\Finance\CostControl\ValueObjects;

class AvcoValuationState
{
    public readonly float $onHandQuantity;
    public readonly ?float $weightedAverageUnitCost;
    public readonly float $carryingValue;
    public readonly string $valuationScope;

    public function __construct(
        float $onHandQuantity,
        ?float $weightedAverageUnitCost,
        float $carryingValue,
        string $valuationScope
    ) {
        $this->onHandQuantity = $onHandQuantity;
        $this->weightedAverageUnitCost = $weightedAverageUnitCost;
        $this->carryingValue = $carryingValue;
        $this->valuationScope = $valuationScope;
    }
}

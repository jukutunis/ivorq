<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

class AvcoValuationState
{
    public readonly string $propertyId;
    public readonly string $itemId;
    public readonly string $valuationScope;
    public readonly AvcoDecimal $onHandQuantity;
    public readonly ?AvcoDecimal $weightedAverageUnitCost;
    public readonly AvcoDecimal $carryingValue;
    public readonly ?ValuationSequence $lastAppliedSequence;
    public readonly AvcoDecimal $unresolvedProvisionalQuantity;

    public function __construct(
        string $propertyId,
        string $itemId,
        string $valuationScope,
        AvcoDecimal $onHandQuantity,
        ?AvcoDecimal $weightedAverageUnitCost,
        AvcoDecimal $carryingValue,
        ?ValuationSequence $lastAppliedSequence = null,
        ?AvcoDecimal $unresolvedProvisionalQuantity = null
    ) {
        if (empty($propertyId) || empty($itemId) || empty($valuationScope)) {
            throw new InvalidArgumentException("Identifiers cannot be empty.");
        }
        if ($lastAppliedSequence !== null) {
            if ($lastAppliedSequence->propertyId !== $propertyId ||
                $lastAppliedSequence->itemId !== $itemId ||
                $lastAppliedSequence->valuationScope !== $valuationScope) {
                throw new InvalidArgumentException("lastAppliedSequence must match state identity.");
            }
        }

        $this->propertyId = $propertyId;
        $this->itemId = $itemId;
        $this->valuationScope = $valuationScope;
        $this->onHandQuantity = $onHandQuantity;
        $this->weightedAverageUnitCost = $weightedAverageUnitCost;
        $this->carryingValue = $carryingValue;
        $this->lastAppliedSequence = $lastAppliedSequence;
        $this->unresolvedProvisionalQuantity = $unresolvedProvisionalQuantity ?? AvcoDecimal::zero();

        if ($this->carryingValue->isNegative()) {
            throw new InvalidArgumentException("carryingValue cannot be negative");
        }
        if ($this->unresolvedProvisionalQuantity->isNegative()) {
            throw new InvalidArgumentException("unresolvedProvisionalQuantity cannot be negative");
        }
        
        if ($this->unresolvedProvisionalQuantity->isPositive()) {
            if (!$this->onHandQuantity->isNegative() || $this->onHandQuantity->abs()->compareTo($this->unresolvedProvisionalQuantity) !== 0) {
                throw new InvalidArgumentException("When provisional balance exists, onHandQuantity must be negative and equal in absolute to unresolved quantity.");
            }
            if ($this->weightedAverageUnitCost !== null) {
                throw new InvalidArgumentException("weightedAverageUnitCost must be null when provisional balance exists");
            }
            if (!$this->carryingValue->isZero()) {
                throw new InvalidArgumentException("carryingValue must be zero when provisional balance exists");
            }
        } else {
            if ($this->onHandQuantity->isNegative()) {
                throw new InvalidArgumentException("onHandQuantity cannot be negative when no provisional balance exists");
            }
            if ($this->onHandQuantity->isZero()) {
                if (!$this->carryingValue->isZero()) {
                    throw new InvalidArgumentException("carryingValue must be zero when onHandQuantity is zero");
                }
                if ($this->weightedAverageUnitCost !== null) {
                    throw new InvalidArgumentException("weightedAverageUnitCost must be null when onHandQuantity is zero");
                }
            } else {
                if ($this->weightedAverageUnitCost === null) {
                    throw new InvalidArgumentException("weightedAverageUnitCost must not be null when onHandQuantity is positive");
                }
            }
        }
    }
}

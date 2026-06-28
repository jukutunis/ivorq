<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable intent carrying input facts for planning an adjustment valuation transition.
 */
final readonly class ControlledAdjustmentValuationIntent
{
    public string $propertyId;
    public string $locationId;
    public string $itemId;
    public string $valuationScope;
    public ?ValuationSequence $currentLastAppliedValuationSequence;
    public AvcoDecimal $currentQuantity;
    public AvcoDecimal $currentCarryingValue;
    public ControlledValuationCostLedgerIntent $costLedgerIntent;

    public function __construct(
        string $propertyId,
        string $locationId,
        string $itemId,
        ?ValuationSequence $currentLastAppliedValuationSequence,
        AvcoDecimal $currentQuantity,
        AvcoDecimal $currentCarryingValue,
        ControlledValuationCostLedgerIntent $costLedgerIntent
    ) {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('Property ID cannot be blank.');
        }
        if (trim($locationId) === '') {
            throw new InvalidArgumentException('Location ID cannot be blank.');
        }
        if (trim($itemId) === '') {
            throw new InvalidArgumentException('Item ID cannot be blank.');
        }

        if ($currentQuantity->isNegative()) {
            throw new InvalidArgumentException('Current quantity cannot be negative.');
        }
        if ($currentCarryingValue->isNegative()) {
            throw new InvalidArgumentException('Current carrying value cannot be negative.');
        }

        $expectedScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";

        if ($currentLastAppliedValuationSequence !== null) {
            if ($currentLastAppliedValuationSequence->propertyId !== $propertyId ||
                $currentLastAppliedValuationSequence->itemId !== $itemId ||
                $currentLastAppliedValuationSequence->valuationScope !== $expectedScope) {
                throw new InvalidArgumentException('Valuation sequence scope mismatch.');
            }
        }

        if ($costLedgerIntent->entryType !== 'adjustment') {
            throw new InvalidArgumentException('Only "adjustment" entryType is supported.');
        }

        if ($costLedgerIntent->propertyId !== $propertyId) {
            throw new InvalidArgumentException('Property ID mismatch in Cost Ledger Intent.');
        }

        $this->propertyId = $propertyId;
        $this->locationId = $locationId;
        $this->itemId = $itemId;
        $this->valuationScope = $expectedScope;
        $this->currentLastAppliedValuationSequence = $currentLastAppliedValuationSequence;
        $this->currentQuantity = $currentQuantity;
        $this->currentCarryingValue = $currentCarryingValue;
        $this->costLedgerIntent = $costLedgerIntent;
    }
}

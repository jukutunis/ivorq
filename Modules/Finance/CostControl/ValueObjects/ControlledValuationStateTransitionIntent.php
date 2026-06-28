<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

/**
 * final readonly value object containing only the input required for a pure
 * controlled receipt valuation plan.
 */
final readonly class ControlledValuationStateTransitionIntent
{
    public string $propertyId;
    public string $locationId;
    public string $itemId;
    public ?ValuationSequence $currentLastAppliedValuationSequence;
    public AvcoDecimal $currentQuantity;
    public AvcoDecimal $currentCarryingValue;
    public ControlledValuationCostLedgerIntent $costLedgerIntent;
    public string $valuationScope;

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
            throw new InvalidArgumentException('propertyId cannot be blank.');
        }
        if (trim($locationId) === '') {
            throw new InvalidArgumentException('locationId cannot be blank.');
        }
        if (trim($itemId) === '') {
            throw new InvalidArgumentException('itemId cannot be blank.');
        }

        if ($currentQuantity->isNegative()) {
            throw new InvalidArgumentException('currentQuantity cannot be negative.');
        }

        if ($currentCarryingValue->isNegative()) {
            throw new InvalidArgumentException('currentCarryingValue cannot be negative.');
        }

        $this->propertyId = $propertyId;
        $this->locationId = $locationId;
        $this->itemId = $itemId;
        $this->currentLastAppliedValuationSequence = $currentLastAppliedValuationSequence;
        $this->currentQuantity = $currentQuantity;
        $this->currentCarryingValue = $currentCarryingValue;
        $this->costLedgerIntent = $costLedgerIntent;

        // build canonical valuation scope only from property, location, and item IDs
        $this->valuationScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";

        // Validate canonical scope and identity matchups
        if ($costLedgerIntent->propertyId !== $propertyId) {
            throw new InvalidArgumentException('Property ID mismatch between transition intent and ledger intent.');
        }

        if ($currentLastAppliedValuationSequence !== null) {
            if ($currentLastAppliedValuationSequence->propertyId !== $propertyId) {
                throw new InvalidArgumentException('Property ID mismatch in last applied sequence.');
            }
            if ($currentLastAppliedValuationSequence->itemId !== $itemId) {
                throw new InvalidArgumentException('Item ID mismatch in last applied sequence.');
            }
            if ($currentLastAppliedValuationSequence->valuationScope !== $this->valuationScope) {
                throw new InvalidArgumentException('Valuation scope mismatch in last applied sequence.');
            }
        }
    }
}

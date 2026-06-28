<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

/**
 * final readonly value object carrying the intent for a paired transfer valuation calculation.
 */
final class ControlledTransferValuationIntent
{
    public readonly string $propertyId;
    public readonly string $itemId;
    public readonly string $sourceLocationId;
    public readonly string $destinationLocationId;
    public readonly ?ValuationSequence $sourceCurrentLastValuationSequence;
    public readonly AvcoDecimal $sourceCurrentQuantity;
    public readonly AvcoDecimal $sourceCurrentCarryingValue;
    public readonly ?ValuationSequence $destinationCurrentLastValuationSequence;
    public readonly AvcoDecimal $destinationCurrentQuantity;
    public readonly AvcoDecimal $destinationCurrentCarryingValue;
    public readonly ControlledValuationCostLedgerIntent $outboundIntent;
    public readonly ControlledValuationCostLedgerIntent $inboundIntent;

    public readonly string $sourceValuationScope;
    public readonly string $destinationValuationScope;

    public function __construct(
        string $propertyId,
        string $itemId,
        string $sourceLocationId,
        string $destinationLocationId,
        ?ValuationSequence $sourceCurrentLastValuationSequence,
        AvcoDecimal $sourceCurrentQuantity,
        AvcoDecimal $sourceCurrentCarryingValue,
        ?ValuationSequence $destinationCurrentLastValuationSequence,
        AvcoDecimal $destinationCurrentQuantity,
        AvcoDecimal $destinationCurrentCarryingValue,
        ControlledValuationCostLedgerIntent $outboundIntent,
        ControlledValuationCostLedgerIntent $inboundIntent
    ) {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('propertyId cannot be blank.');
        }
        if (trim($itemId) === '') {
            throw new InvalidArgumentException('itemId cannot be blank.');
        }
        if (trim($sourceLocationId) === '') {
            throw new InvalidArgumentException('sourceLocationId cannot be blank.');
        }
        if (trim($destinationLocationId) === '') {
            throw new InvalidArgumentException('destinationLocationId cannot be blank.');
        }
        if ($sourceLocationId === $destinationLocationId) {
            throw new InvalidArgumentException('Source and destination locations cannot be the same.');
        }
        if ($sourceCurrentQuantity->isNegative()) {
            throw new InvalidArgumentException('Source quantity cannot be negative.');
        }
        if ($sourceCurrentCarryingValue->isNegative()) {
            throw new InvalidArgumentException('Source carrying value cannot be negative.');
        }
        if ($destinationCurrentQuantity->isNegative()) {
            throw new InvalidArgumentException('Destination quantity cannot be negative.');
        }
        if ($destinationCurrentCarryingValue->isNegative()) {
            throw new InvalidArgumentException('Destination carrying value cannot be negative.');
        }

        // Validate canonical scopes matchup
        $this->sourceValuationScope = "property:{$propertyId}:location:{$sourceLocationId}:item:{$itemId}";
        $this->destinationValuationScope = "property:{$propertyId}:location:{$destinationLocationId}:item:{$itemId}";

        if ($outboundIntent->propertyId !== $propertyId || $inboundIntent->propertyId !== $propertyId) {
            throw new InvalidArgumentException('Property ID mismatch on intents.');
        }

        $this->propertyId = $propertyId;
        $this->itemId = $itemId;
        $this->sourceLocationId = $sourceLocationId;
        $this->destinationLocationId = $destinationLocationId;
        $this->sourceCurrentLastValuationSequence = $sourceCurrentLastValuationSequence;
        $this->sourceCurrentQuantity = $sourceCurrentQuantity;
        $this->sourceCurrentCarryingValue = $sourceCurrentCarryingValue;
        $this->destinationCurrentLastValuationSequence = $destinationCurrentLastValuationSequence;
        $this->destinationCurrentQuantity = $destinationCurrentQuantity;
        $this->destinationCurrentCarryingValue = $destinationCurrentCarryingValue;
        $this->outboundIntent = $outboundIntent;
        $this->inboundIntent = $inboundIntent;
    }
}

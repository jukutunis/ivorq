<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

class ValuationSequence
{
    public readonly string $propertyId;
    public readonly string $itemId;
    public readonly string $valuationScope;
    public readonly string $businessDate;
    public readonly int $ledgerSequence;

    public function __construct(
        string $propertyId,
        string $itemId,
        string $valuationScope,
        string $businessDate,
        int $ledgerSequence
    ) {
        if ($ledgerSequence <= 0) {
            throw new InvalidArgumentException("ledgerSequence must be positive");
        }
        if (empty($propertyId) || empty($itemId) || empty($valuationScope) || empty($businessDate)) {
            throw new InvalidArgumentException("identifiers and businessDate cannot be empty");
        }

        $this->propertyId = $propertyId;
        $this->itemId = $itemId;
        $this->valuationScope = $valuationScope;
        $this->businessDate = $businessDate;
        $this->ledgerSequence = $ledgerSequence;
    }
}

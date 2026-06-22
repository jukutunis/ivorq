<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

class TransferValuationContext
{
    public readonly string $sourcePropertyId;
    public readonly string $sourceItemId;
    public readonly string $sourceValuationScope;
    public readonly AvcoDecimal $sourceCarryingUnitCost;

    public function __construct(
        string $sourcePropertyId,
        string $sourceItemId,
        string $sourceValuationScope,
        AvcoDecimal $sourceCarryingUnitCost
    ) {
        if (empty($sourcePropertyId) || empty($sourceItemId) || empty($sourceValuationScope)) {
            throw new InvalidArgumentException("Source identifiers cannot be empty.");
        }
        if ($sourceCarryingUnitCost->isNegative()) {
            throw new InvalidArgumentException("sourceCarryingUnitCost cannot be negative.");
        }
        $this->sourcePropertyId = $sourcePropertyId;
        $this->sourceItemId = $sourceItemId;
        $this->sourceValuationScope = $sourceValuationScope;
        $this->sourceCarryingUnitCost = $sourceCarryingUnitCost;
    }
}

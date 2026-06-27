<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

class TransferValuationContext
{
    public readonly string $sourcePropertyId;
    public readonly string $sourceItemId;
    public readonly string $sourceValuationScope;
    public readonly AvcoDecimal $sourceCarryingUnitCost;
    public readonly ?string $destinationValuationScope;

    public function __construct(
        string $sourcePropertyId,
        string $sourceItemId,
        string $sourceValuationScope,
        AvcoDecimal $sourceCarryingUnitCost,
        ?string $destinationValuationScope = null
    ) {
        if (empty($sourcePropertyId) || empty($sourceItemId) || empty($sourceValuationScope)) {
            throw new InvalidArgumentException("Source identifiers cannot be empty.");
        }
        if ($sourceCarryingUnitCost->isNegative()) {
            throw new InvalidArgumentException("sourceCarryingUnitCost cannot be negative.");
        }
        if ($destinationValuationScope !== null && empty($destinationValuationScope)) {
            throw new InvalidArgumentException("destinationValuationScope cannot be empty when provided.");
        }
        $this->sourcePropertyId = $sourcePropertyId;
        $this->sourceItemId = $sourceItemId;
        $this->sourceValuationScope = $sourceValuationScope;
        $this->sourceCarryingUnitCost = $sourceCarryingUnitCost;
        $this->destinationValuationScope = $destinationValuationScope;
    }
}

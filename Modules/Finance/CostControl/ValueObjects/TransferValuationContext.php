<?php

namespace Modules\Finance\CostControl\ValueObjects;

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
        $this->sourcePropertyId = $sourcePropertyId;
        $this->sourceItemId = $sourceItemId;
        $this->sourceValuationScope = $sourceValuationScope;
        $this->sourceCarryingUnitCost = $sourceCarryingUnitCost;
    }
}

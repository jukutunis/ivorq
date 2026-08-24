<?php

namespace Modules\Operations\Inventory\Contracts;

use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;

interface CostDeliveryModePort
{
    public function resolveForPosting(
        string $propertyId,
        string $itemId,
        string $locationId
    ): CostDeliveryPostingDecision;
}

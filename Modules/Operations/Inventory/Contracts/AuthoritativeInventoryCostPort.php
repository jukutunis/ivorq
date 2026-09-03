<?php

namespace Modules\Operations\Inventory\Contracts;

use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;

interface AuthoritativeInventoryCostPort
{
    public function resolveUnitCostForPosting(CostDeliveryPostingDecision $prelockedDecision): string;
}

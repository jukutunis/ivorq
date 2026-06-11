<?php

namespace Modules\Operations\ContractorPTW\Contracts;

interface WorkOrderPermitRequirement
{
    public function requiresPermit(string $workOrderId): bool;
}

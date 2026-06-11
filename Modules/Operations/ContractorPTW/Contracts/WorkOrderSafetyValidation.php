<?php

namespace Modules\Operations\ContractorPTW\Contracts;

interface WorkOrderSafetyValidation
{
    public function isWorkOrderSafeToStart(string $workOrderId): bool;
}

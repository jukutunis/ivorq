<?php

namespace Modules\Operations\Inventory\Contracts;

interface InventoryCostTrackingContract
{
    public function trackCost(string $transactionId): void;
}

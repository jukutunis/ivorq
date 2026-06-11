<?php

namespace Modules\Operations\Inventory\Contracts;

interface InventoryAvailabilityCheck
{
    public function check(string $propertyId, string $itemId, string $locationId, float $requiredQuantity): bool;
}

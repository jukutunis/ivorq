<?php

namespace Modules\Operations\Inventory\Contracts;

interface InventoryReservationContract
{
    public function reserve(string $propertyId, string $itemId, string $locationId, float $quantity, string $workOrderId): string;
}

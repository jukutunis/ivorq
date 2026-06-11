<?php

namespace Modules\Operations\Inventory\Contracts;

interface InventoryConsumptionContract
{
    public function consume(string $reservationId, float $consumedQuantity): void;
}

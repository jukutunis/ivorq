<?php

namespace Modules\OperationalMetrics\Contracts;

interface OccupancyActualInterface
{
    public function getPropertyId(): string;
    public function getBusinessDate(): string;
    public function getOccupancyPercentage(): float;
    public function getOccupiedRooms(): int;
}

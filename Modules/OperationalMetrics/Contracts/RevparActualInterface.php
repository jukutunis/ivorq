<?php

namespace Modules\OperationalMetrics\Contracts;

interface RevparActualInterface
{
    public function getPropertyId(): string;
    public function getBusinessDate(): string;
    public function getRevenuePerAvailableRoom(): float;
}

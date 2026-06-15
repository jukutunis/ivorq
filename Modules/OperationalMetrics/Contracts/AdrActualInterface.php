<?php

namespace Modules\OperationalMetrics\Contracts;

interface AdrActualInterface
{
    public function getPropertyId(): string;
    public function getBusinessDate(): string;
    public function getAverageDailyRate(): float;
}

<?php

namespace Modules\OperationalMetrics\Contracts;

interface CoversActualInterface
{
    public function getPropertyId(): string;
    public function getBusinessDate(): string;
    public function getOutletId(): string;
    public function getTotalCovers(): int;
}

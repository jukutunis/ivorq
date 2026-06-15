<?php

namespace Modules\OperationalMetrics\Contracts;

interface LaborHoursActualInterface
{
    public function getPropertyId(): string;
    public function getBusinessDate(): string;
    public function getDepartmentId(): string;
    public function getTotalHoursWorked(): float;
}

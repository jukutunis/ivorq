<?php

namespace Modules\WorkforcePlanning\Contracts;

interface BudgetPositionInterface
{
    public function getPositionId(): string;
    public function getPropertyId(): string;
    public function getDepartmentId(): string;
    public function getFteCount(): float;
    public function getBaseSalary(): float;
}

<?php

namespace Modules\PlanningAndBudgeting\Contracts;

interface ActualSourceContract
{
    /**
     * Retrieve the actual amount for a specific budget category, department, and period.
     * Support for future Financial, Operational, and Labor Actuals.
     *
     * @param string $companyId
     * @param string $propertyId
     * @param string $departmentId
     * @param string $budgetCategoryId
     * @param int $periodNumber
     * @return float
     */
    public function getActualAmount(
        string $companyId,
        string $propertyId,
        string $departmentId,
        string $budgetCategoryId,
        int $periodNumber
    ): float;
}

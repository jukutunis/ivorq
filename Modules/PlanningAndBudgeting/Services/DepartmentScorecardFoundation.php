<?php

namespace Modules\PlanningAndBudgeting\Services;

use Modules\PlanningAndBudgeting\DTOs\PerformanceMatrixDTO;
use Modules\PlanningAndBudgeting\Models\BudgetVersion;
use Modules\PlanningAndBudgeting\Models\ForecastVersion;

class DepartmentScorecardFoundation
{
    protected EpmQueryEngine $queryEngine;

    public function __construct(EpmQueryEngine $queryEngine)
    {
        $this->queryEngine = $queryEngine;
    }

    /**
     * Generate a Department Scorecard for ANY dynamically provided department ID
     */
    public function generateScorecard(
        string $companyId,
        string $propertyId,
        string $departmentId,
        BudgetVersion $budgetVersion,
        ?ForecastVersion $forecastVersion,
        int $periodNumber
    ): PerformanceMatrixDTO {
        // Enforces dynamic department logic, avoiding hardcoded "if FrontOffice" checks
        return $this->queryEngine->queryDepartmentPerformance(
            $companyId,
            $propertyId,
            $departmentId,
            $budgetVersion,
            $forecastVersion,
            $periodNumber
        );
    }
}

<?php

namespace Modules\PlanningAndBudgeting\Services;

use Modules\PlanningAndBudgeting\DTOs\PerformanceMatrixDTO;
use Modules\PlanningAndBudgeting\Contracts\ActualSourceContract;
use Modules\PlanningAndBudgeting\Models\BudgetVersion;
use Modules\PlanningAndBudgeting\Models\ForecastVersion;
use Exception;

class EpmQueryEngine
{
    protected ActualSourceContract $actualSource;

    public function __construct(ActualSourceContract $actualSource)
    {
        $this->actualSource = $actualSource;
    }

    /**
     * Query EPM Data for a single department
     */
    public function queryDepartmentPerformance(
        string $companyId,
        string $propertyId,
        string $departmentId,
        BudgetVersion $budgetVersion,
        ?ForecastVersion $forecastVersion,
        int $periodNumber
    ): PerformanceMatrixDTO {
        $this->enforceIsolation($companyId, $propertyId, $budgetVersion);

        if ($forecastVersion) {
            $this->enforceIsolation($companyId, $propertyId, $forecastVersion);
        }

        $matrix = new PerformanceMatrixDTO($periodNumber);
        $matrix->setCompanyContext($companyId)
               ->setPropertyContext($propertyId)
               ->setDepartmentContext($departmentId);

        // Foundation implementation: 
        // 1. Fetch Budget Entries
        // 2. Fetch Forecast Entries
        // 3. Fetch Actuals via ActualSourceContract
        // 4. Map into VarianceDTOs and append to Matrix

        return $matrix;
    }

    /**
     * Enforce strict property isolation when querying EPM data
     */
    private function enforceIsolation(string $companyId, string $propertyId, $versionModel): void
    {
        // Using relationship via forecast/budget to verify property_id
        $modelCompanyId = null;
        $modelPropertyId = null;

        if ($versionModel instanceof BudgetVersion) {
            $modelCompanyId = $versionModel->company_id;
            $modelPropertyId = $versionModel->property_id;
        } elseif ($versionModel instanceof ForecastVersion) {
            $modelCompanyId = $versionModel->forecast->company_id;
            $modelPropertyId = $versionModel->forecast->property_id;
        }

        if ($modelCompanyId !== $companyId || $modelPropertyId !== $propertyId) {
            throw new Exception("GovernanceException: Cross-property or cross-company EPM access is forbidden.");
        }
    }
}

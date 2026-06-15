<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Modules\PlanningAndBudgeting\DTOs\VarianceDTO;
use Modules\PlanningAndBudgeting\DTOs\PerformanceMatrixDTO;
use Modules\PlanningAndBudgeting\Services\EpmQueryEngine;
use Modules\PlanningAndBudgeting\Services\DepartmentScorecardFoundation;
use Modules\PlanningAndBudgeting\Services\PropertyScorecardFoundation;
use Modules\PlanningAndBudgeting\Contracts\ActualSourceContract;
use Modules\PlanningAndBudgeting\Models\BudgetVersion;
use Modules\PlanningAndBudgeting\Models\ForecastVersion;
use Modules\PlanningAndBudgeting\Models\Forecast;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MockActualSource implements ActualSourceContract
{
    public function getActualAmount(
        string $companyId,
        string $propertyId,
        string $departmentId,
        string $budgetCategoryId,
        int $periodNumber
    ): float {
        return 95000.0;
    }
}

class BudgetVsActualFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function createBudgetVersion(): BudgetVersion
    {
        $cycle = \Modules\PlanningAndBudgeting\Models\BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => \Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum::Draft,
        ]);

        $scenario = \Modules\PlanningAndBudgeting\Models\BudgetScenario::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_cycle_id' => $cycle->id,
            'name' => 'Base',
        ]);

        return BudgetVersion::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_scenario_id' => $scenario->id,
            'version_number' => 1,
        ]);
    }

    public function test_variance_dto_calculations()
    {
        $dto = new VarianceDTO(
            'Room Revenue',
            100000.0, // Budget
            98000.0,  // Forecast
            95000.0   // Actual
        );

        $this->assertEquals('Room Revenue', $dto->categoryName);
        $this->assertEquals(-5000.0, $dto->varianceToBudget);
        $this->assertEquals(-5.0, $dto->varianceToBudgetPercent);
        
        $this->assertEquals(-3000.0, $dto->varianceToForecast);
        $this->assertEquals(-3.061224489795918, $dto->varianceToForecastPercent);
        
        $this->assertNull($dto->varianceReason);
        $this->assertNull($dto->varianceComment);
    }

    public function test_performance_matrix_context()
    {
        $matrix = new PerformanceMatrixDTO(1);
        $matrix->setCompanyContext('comp_1')
               ->setPropertyContext('prop_1')
               ->setDepartmentContext('dept_rooms');

        $this->assertEquals('comp_1', $matrix->companyId);
        $this->assertEquals('prop_1', $matrix->propertyId);
        $this->assertEquals('dept_rooms', $matrix->departmentId);
        $this->assertEquals(1, $matrix->periodNumber);

        $variance = new VarianceDTO('Room Revenue', 100, 100, 90);
        $matrix->addVariance($variance);

        $this->assertCount(1, $matrix->variances);
        $this->assertEquals('Room Revenue', $matrix->variances[0]->categoryName);
    }

    public function test_epm_query_engine_property_isolation()
    {
        $actualSource = new MockActualSource();
        $engine = new EpmQueryEngine($actualSource);

        $budgetVersion = $this->createBudgetVersion();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cross-property or cross-company EPM access is forbidden/');

        $engine->queryDepartmentPerformance(
            'comp_1',
            'prop_2', // Wrong property
            'dept_1',
            $budgetVersion,
            null,
            1
        );
    }

    public function test_department_scorecard_foundation()
    {
        $actualSource = new MockActualSource();
        $engine = new EpmQueryEngine($actualSource);
        $departmentScorecard = new DepartmentScorecardFoundation($engine);

        $budgetVersion = $this->createBudgetVersion();

        $matrix = $departmentScorecard->generateScorecard(
            'comp_1',
            'prop_1',
            'dept_spa', // Dynamic department
            $budgetVersion,
            null,
            2
        );

        $this->assertInstanceOf(PerformanceMatrixDTO::class, $matrix);
        $this->assertEquals('dept_spa', $matrix->departmentId);
        $this->assertEquals(2, $matrix->periodNumber);
    }

    public function test_property_scorecard_foundation()
    {
        $propertyScorecard = new PropertyScorecardFoundation();

        $budgetVersion = $this->createBudgetVersion();

        $propertyMatrix = $propertyScorecard->generatePropertyScorecard(
            'comp_1',
            'prop_1',
            $budgetVersion,
            null,
            3
        );

        $this->assertEquals('comp_1', $propertyMatrix->companyId);
        $this->assertEquals('prop_1', $propertyMatrix->propertyId);
        $this->assertNull($propertyMatrix->departmentId);

        $companyMatrix = $propertyScorecard->generateCompanyScorecard(
            'comp_1',
            4
        );

        $this->assertEquals('comp_1', $companyMatrix->companyId);
        $this->assertNull($companyMatrix->propertyId);
    }
}

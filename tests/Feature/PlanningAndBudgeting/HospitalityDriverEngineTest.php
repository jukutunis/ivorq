<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\BudgetCycle;
use Modules\PlanningAndBudgeting\Models\BudgetScenario;
use Modules\PlanningAndBudgeting\Models\BudgetVersion;
use Modules\PlanningAndBudgeting\Models\RevenueAssumption;
use Modules\PlanningAndBudgeting\Models\OperationalAssumption;
use Modules\PlanningAndBudgeting\Models\LaborAssumption;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;
use Modules\PlanningAndBudgeting\Enums\RevenueMetricEnum;
use Modules\PlanningAndBudgeting\Enums\OperationalMetricEnum;
use Modules\PlanningAndBudgeting\Enums\LaborMetricEnum;
use Illuminate\Support\Str;

class HospitalityDriverEngineTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $cycle = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
        ]);

        $scenario = BudgetScenario::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_cycle_id' => $cycle->id,
            'name' => 'Base',
        ]);

        $this->version = BudgetVersion::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_scenario_id' => $scenario->id,
            'version_number' => 1,
        ]);
    }

    public function test_revenue_assumption_creation_and_relationships()
    {
        $assumption = RevenueAssumption::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'metric_type' => RevenueMetricEnum::Occupancy,
            'period_number' => 1,
            'value' => 75.5,
            'room_type_id' => 'room_type_1',
            'market_segment_id' => 'segment_1',
            'channel_id' => 'channel_1',
        ]);

        $this->assertInstanceOf(BudgetVersion::class, $assumption->budgetVersion);
        $this->assertEquals(RevenueMetricEnum::Occupancy, $assumption->metric_type);
        $this->assertEquals(75.5, $assumption->value);
        $this->assertDatabaseHas('revenue_assumptions', [
            'id' => $assumption->id,
            'property_id' => 'prop_1',
            'period_number' => 1,
        ]);
    }

    public function test_operational_assumption_creation()
    {
        $assumption = OperationalAssumption::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'metric_type' => OperationalMetricEnum::Covers,
            'period_number' => 2,
            'value' => 1500,
            'department_id' => 'dept_fb',
            'outlet_id' => 'outlet_1',
        ]);

        $this->assertEquals(OperationalMetricEnum::Covers, $assumption->metric_type);
        $this->assertEquals(1500, $assumption->value);
        $this->assertDatabaseHas('operational_assumptions', [
            'id' => $assumption->id,
            'outlet_id' => 'outlet_1',
        ]);
    }

    public function test_labor_assumption_creation()
    {
        $assumption = LaborAssumption::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'metric_type' => LaborMetricEnum::PayrollPercent,
            'period_number' => 3,
            'value' => 35.0,
            'department_id' => 'dept_rooms',
            'position_id' => 'pos_housekeeper',
        ]);

        $this->assertEquals(LaborMetricEnum::PayrollPercent, $assumption->metric_type);
        $this->assertEquals(35.0, $assumption->value);
        $this->assertDatabaseHas('labor_assumptions', [
            'id' => $assumption->id,
            'department_id' => 'dept_rooms',
            'position_id' => 'pos_housekeeper',
        ]);
    }

    public function test_property_isolation_on_assumptions()
    {
        $cycle2 = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_2',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget Prop 2',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
        ]);

        $scenario2 = BudgetScenario::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_2',
            'budget_cycle_id' => $cycle2->id,
            'name' => 'Base',
        ]);

        $version2 = BudgetVersion::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_2',
            'budget_scenario_id' => $scenario2->id,
            'version_number' => 1,
        ]);

        RevenueAssumption::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'metric_type' => RevenueMetricEnum::Adr,
            'period_number' => 1,
            'value' => 200,
        ]);

        RevenueAssumption::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_2',
            'budget_version_id' => $version2->id,
            'metric_type' => RevenueMetricEnum::Adr,
            'period_number' => 1,
            'value' => 250,
        ]);

        $prop1Assumptions = RevenueAssumption::where('property_id', 'prop_1')->get();
        $this->assertCount(1, $prop1Assumptions);
        $this->assertEquals(200, $prop1Assumptions->first()->value);
    }
}

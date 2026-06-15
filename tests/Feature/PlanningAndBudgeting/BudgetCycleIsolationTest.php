<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\BudgetCycle;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;

class BudgetCycleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_cycle_enforces_property_isolation()
    {
        $cycleProp1 = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Prop 1 Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
        ]);

        $cycleProp2 = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_2',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Prop 2 Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
        ]);

        $cyclesInProp1 = BudgetCycle::where('property_id', 'prop_1')->get();
        
        $this->assertCount(1, $cyclesInProp1);
        $this->assertEquals('prop_1', $cyclesInProp1->first()->property_id);
    }
}

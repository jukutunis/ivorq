<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\BudgetCycle;
use Modules\PlanningAndBudgeting\Models\BenchmarkTemplate;
use Modules\PlanningAndBudgeting\Models\BenchmarkTarget;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;
use Modules\PlanningAndBudgeting\Enums\BenchmarkTargetStatusEnum;

class BenchmarkFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_can_adopt_corporate_benchmark()
    {
        $cycle = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
        ]);

        $template = BenchmarkTemplate::create([
            'company_id' => 'comp_1',
            'name' => 'Payroll %',
            'metric_type' => 'PERCENTAGE',
        ]);

        $target = BenchmarkTarget::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'benchmark_template_id' => $template->id,
            'budget_cycle_id' => $cycle->id,
            'target_value' => 30.5,
            'adopted_value' => 30.5,
            'status' => BenchmarkTargetStatusEnum::Adopted,
        ]);

        $this->assertDatabaseHas('benchmark_targets', [
            'id' => $target->id,
            'status' => 'ADOPTED',
            'adopted_value' => 30.5,
        ]);
    }

    public function test_property_can_override_benchmark_with_justification()
    {
        $cycle = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
        ]);

        $template = BenchmarkTemplate::create([
            'company_id' => 'comp_1',
            'name' => 'Food Cost %',
            'metric_type' => 'PERCENTAGE',
        ]);

        $target = BenchmarkTarget::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'benchmark_template_id' => $template->id,
            'budget_cycle_id' => $cycle->id,
            'target_value' => 25.0,
            'adopted_value' => 28.0,
            'status' => BenchmarkTargetStatusEnum::Overridden,
            'justification' => 'New supplier contracts increased local costs.',
        ]);

        $this->assertDatabaseHas('benchmark_targets', [
            'id' => $target->id,
            'status' => 'OVERRIDDEN',
            'justification' => 'New supplier contracts increased local costs.',
        ]);
    }
}

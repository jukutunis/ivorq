<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\BudgetCycle;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;
use Modules\PlanningAndBudgeting\Services\PlanningStateGuard;
use Illuminate\Support\Str;

class ApprovalGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_cannot_approve_their_own_budget_cycle()
    {
        $creatorId = (string) Str::ulid();

        $cycle = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::InReview,
            'created_by' => $creatorId,
        ]);

        $guard = new PlanningStateGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Creator cannot approve their own Budget Cycle/');

        $guard->transitionTo($cycle, BudgetCycleStatusEnum::Approved, $creatorId);
    }
}

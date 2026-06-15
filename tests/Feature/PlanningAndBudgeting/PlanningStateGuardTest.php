<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\BudgetCycle;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;
use Modules\PlanningAndBudgeting\Services\PlanningStateGuard;
use Illuminate\Support\Str;

class PlanningStateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected PlanningStateGuard $guard;
    protected BudgetCycle $cycle;
    protected string $creatorId;
    protected string $approverId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new PlanningStateGuard();
        $this->creatorId = (string) Str::ulid();
        $this->approverId = (string) Str::ulid();

        $this->cycle = BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget 2027',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => BudgetCycleStatusEnum::Draft,
            'created_by' => $this->creatorId,
        ]);
    }

    public function test_valid_transitions()
    {
        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::Open, $this->creatorId);
        $this->assertEquals(BudgetCycleStatusEnum::Open, $this->cycle->status);

        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::InReview, $this->creatorId);
        $this->assertEquals(BudgetCycleStatusEnum::InReview, $this->cycle->status);

        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::Approved, $this->approverId);
        $this->assertEquals(BudgetCycleStatusEnum::Approved, $this->cycle->status);
        $this->assertEquals($this->approverId, $this->cycle->approved_by);

        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::Locked, $this->creatorId);
        $this->assertEquals(BudgetCycleStatusEnum::Locked, $this->cycle->status);
    }

    public function test_creator_cannot_approve()
    {
        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::Open, $this->creatorId);
        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::InReview, $this->creatorId);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Creator cannot approve/');
        
        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::Approved, $this->creatorId);
    }

    public function test_invalid_transitions()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Invalid transition to IN_REVIEW/');
        
        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::InReview, $this->creatorId);
    }

    public function test_cannot_transition_from_locked()
    {
        $this->cycle->update(['status' => BudgetCycleStatusEnum::Locked]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Cannot transition from LOCKED/');
        
        $this->guard->transitionTo($this->cycle, BudgetCycleStatusEnum::Open, $this->creatorId);
    }
}

<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Services\PurchaseRequestService;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Foundation\Department\Models\Department;
use Modules\Finance\Budgeting\Models\Budget;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Shared\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Cache;

class BudgetEnforcementIntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected $property;
    protected $department;
    protected $user;
    protected $account;
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->property = $this->createProperty($this->createCompany());
        $this->department = Department::create(['property_id' => $this->property->id, 'name' => 'IT', 'code' => 'IT']);
        $this->user = $this->createUser($this->property);
        $this->account = Account::create([
            'property_id' => $this->property->id,
            'code' => '6000',
            'name' => 'IT Expense',
            'account_type' => AccountTypeEnum::Expense->value,
            'account_category' => \Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum::Expense->value,
        ]);
        $this->service = app(PurchaseRequestService::class);
    }

    protected function setupBudget(float $amount, int $month)
    {
        $budget = Budget::create([
            'property_id' => $this->property->id,
            'fiscal_year' => now()->year,
            'name' => 'Annual Budget',
        ]);

        $version = BudgetVersion::create([
            'budget_id' => $budget->id,
            'version_number' => 1,
            'status' => BudgetVersionStatusEnum::Locked->value,
        ]);

        BudgetLine::create([
            'budget_version_id' => $version->id,
            'department_id' => $this->department->id,
            'account_id' => $this->account->id,
            'period_month' => $month,
            'amount' => $amount,
        ]);

        Cache::put("budget:active:{$this->property->id}:" . now()->year, $version->id);
    }

    public function test_purchase_request_within_budget_succeeds()
    {
        $this->setupBudget(5000, now()->month);

        $workflow = \Modules\Foundation\Approval\Models\ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'approvable_type' => PurchaseRequest::class,
            'name' => 'PR Approval',
            'is_active' => true,
        ]);

        \Modules\Foundation\Approval\Models\ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Manager Approval',
            'required_approvals' => 1,
        ]);

        $pr = PurchaseRequest::create([
            'property_id' => $this->property->id, 
            'request_no' => 'PR-BUDGET-1', 
            'department_id' => $this->department->id,
            'requester_id' => $this->user->id,
            'required_date' => now(),
            'estimated_total' => 4000,
            'status' => PurchaseRequestStatusEnum::Draft->value
        ]);

        $submittedPr = $this->service->submit($pr->id);

        $this->assertEquals(PurchaseRequestStatusEnum::PendingReview->value, $submittedPr->status->value);
    }

    public function test_purchase_request_over_budget_throws_exception()
    {
        $this->setupBudget(3000, now()->month);

        $pr = PurchaseRequest::create([
            'property_id' => $this->property->id, 
            'request_no' => 'PR-BUDGET-2', 
            'department_id' => $this->department->id,
            'requester_id' => $this->user->id,
            'required_date' => now(),
            'estimated_total' => 4000,
            'status' => PurchaseRequestStatusEnum::Draft->value
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('Purchase Request amount (4000) exceeds available department budget (3000)');

        $this->service->submit($pr->id);
    }

    public function test_purchase_request_no_budget_throws_exception()
    {
        // No budget configured
        $pr = PurchaseRequest::create([
            'property_id' => $this->property->id, 
            'request_no' => 'PR-BUDGET-3', 
            'department_id' => $this->department->id,
            'requester_id' => $this->user->id,
            'required_date' => now(),
            'estimated_total' => 1000,
            'status' => PurchaseRequestStatusEnum::Draft->value
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('No active budget found for the period.');

        $this->service->submit($pr->id);
    }
}

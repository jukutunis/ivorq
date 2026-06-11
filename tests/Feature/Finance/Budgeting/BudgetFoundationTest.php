<?php

namespace Tests\Feature\Finance\Budgeting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Modules\Finance\Budgeting\Services\BudgetService;
use Modules\Finance\Budgeting\Services\BudgetVarianceService;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\BudgetApproval;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Illuminate\Support\Facades\Cache;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;

class BudgetFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;
    protected BudgetService $service;
    protected BudgetVarianceService $varianceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
        $this->service = app(BudgetService::class);
        $this->varianceService = app(BudgetVarianceService::class);
    }

    protected function createAccount(AccountTypeEnum $type): Account
    {
        $category = match ($type) {
            AccountTypeEnum::Asset => AccountCategoryEnum::CurrentAsset,
            AccountTypeEnum::Liability => AccountCategoryEnum::CurrentLiability,
            AccountTypeEnum::Equity => AccountCategoryEnum::Equity,
            AccountTypeEnum::Revenue => AccountCategoryEnum::Revenue,
            AccountTypeEnum::CostOfSales => AccountCategoryEnum::CostOfSales,
            AccountTypeEnum::Expense => AccountCategoryEnum::Expense,
            default => AccountCategoryEnum::Expense,
        };

        return Account::create([
            'property_id' => $this->propertyId,
            'code' => 'B-' . rand(1000, 9999),
            'name' => 'Budget Account',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => $type,
            'account_category' => $category,
            'is_cash_equivalent' => false,
        ]);
    }

    public function test_budget_allows_only_pl_accounts()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $version = $budget->versions->first();
        
        $revAccount = $this->createAccount(AccountTypeEnum::Revenue);
        $expAccount = $this->createAccount(AccountTypeEnum::Expense);

        $this->service->addLine($version->id, null, $revAccount->id, 1, 1000);
        $this->service->addLine($version->id, null, $expAccount->id, 1, 500);

        $this->assertEquals(2, $version->lines()->count());
    }

    public function test_budget_blocks_balance_sheet_accounts()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $version = $budget->versions->first();
        $assetAccount = $this->createAccount(AccountTypeEnum::Asset);

        $this->expectException(ValidationException::class);
        $this->service->addLine($version->id, null, $assetAccount->id, 1, 1000);
    }

    public function test_only_one_approved_version_per_year()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();
        $this->service->approveVersion($v1->id, 'sys');

        $v2 = $budget->versions()->create([
            'version_number' => 2,
            'status' => BudgetVersionStatusEnum::Draft,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->approveVersion($v2->id, 'sys');
    }

    public function test_locked_budget_is_immutable()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();
        $this->service->lockVersion($v1->id, 'sys');

        $account = $this->createAccount(AccountTypeEnum::Expense);

        $this->expectException(ValidationException::class);
        $this->service->addLine($v1->id, null, $account->id, 1, 1000);
    }

    public function test_budget_variance_is_read_only()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();
        $account = $this->createAccount(AccountTypeEnum::Expense);
        $this->service->addLine($v1->id, null, $account->id, 1, 1000);
        $this->service->approveVersion($v1->id, 'sys');

        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $account->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 800,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        $initialCount = LedgerBalance::count();
        $variance = $this->varianceService->getVariance($this->propertyId, 2027, 1);
        
        $this->assertCount(1, $variance);
        $this->assertEquals(800, $variance[0]['actual_amount']);
        $this->assertEquals(200, $variance[0]['variance_amount']);
        $this->assertEquals($initialCount, LedgerBalance::count()); // Read-only
    }

    public function test_duplicate_budget_lines_blocked()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();
        $account = $this->createAccount(AccountTypeEnum::Expense);

        $this->service->addLine($v1->id, null, $account->id, 1, 1000);

        $this->expectException(ValidationException::class);
        $this->service->addLine($v1->id, null, $account->id, 1, 500); // duplicate
    }

    public function test_budget_approval_audit_created()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();

        $this->service->submitVersion($v1->id, 'maker123');
        
        $this->assertDatabaseHas('budget_budget_approvals', [
            'budget_version_id' => $v1->id,
            'action_by_id' => 'maker123',
            'action' => 'Submitted',
        ]);
    }

    public function test_budget_property_isolation()
    {
        $budget1 = $this->service->createBudget($this->propertyId, 2027, 'Prop1');
        
        $otherProp = (string) Str::ulid();
        $budget2 = $this->service->createBudget($otherProp, 2027, 'Prop2');

        $this->assertNotEquals($budget1->property_id, $budget2->property_id);
    }

    public function test_budget_department_support()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();
        $account = $this->createAccount(AccountTypeEnum::Expense);
        $deptId = (string) Str::ulid();

        $line = $this->service->addLine($v1->id, $deptId, $account->id, 1, 1000);
        $this->assertEquals($deptId, $line->department_id);
    }

    public function test_budget_active_cache_created()
    {
        $budget = $this->service->createBudget($this->propertyId, 2027, 'Test');
        $v1 = $budget->versions->first();
        
        $this->service->approveVersion($v1->id, 'sys');
        
        $cacheKey = "budget:active:{$this->propertyId}:2027";
        $this->assertEquals($v1->id, Cache::get($cacheKey));
    }
}

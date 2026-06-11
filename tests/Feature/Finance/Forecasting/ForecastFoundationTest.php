<?php

namespace Tests\Feature\Finance\Forecasting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Modules\Finance\Forecasting\Services\ForecastService;
use Modules\Finance\Forecasting\Services\ForecastVarianceService;
use Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum;
use Modules\Finance\Budgeting\Services\BudgetService;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Illuminate\Support\Facades\Cache;

class ForecastFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;
    protected ForecastService $service;
    protected ForecastVarianceService $varianceService;
    protected BudgetService $budgetService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
        $this->service = app(ForecastService::class);
        $this->varianceService = app(ForecastVarianceService::class);
        $this->budgetService = app(BudgetService::class);
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
            'code' => 'F-' . rand(1000, 9999),
            'name' => 'Forecast Account',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => $type,
            'account_category' => $category,
            'is_cash_equivalent' => false,
        ]);
    }

    public function test_forecast_auto_seed_actual_plus_budget()
    {
        $account = $this->createAccount(AccountTypeEnum::Expense);
        
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $account->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 100,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        $budget = $this->budgetService->createBudget($this->propertyId, 2027, 'Budget 2027');
        $bVersion = $budget->versions->first();
        $this->budgetService->addLine($bVersion->id, null, $account->id, 1, 200);
        $this->budgetService->addLine($bVersion->id, null, $account->id, 2, 300);
        $this->budgetService->approveVersion($bVersion->id, 'sys');

        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Forecast 2027');
        $fVersion = $forecast->versions->first();

        $lines = $fVersion->lines;
        $this->assertCount(2, $lines);
        
        $m1 = $lines->where('period_month', 1)->first();
        $this->assertEquals(100, $m1->amount);

        $m2 = $lines->where('period_month', 2)->first();
        $this->assertEquals(300, $m2->amount);
    }

    public function test_forecast_allows_only_pl_accounts()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $version = $forecast->versions->first();
        
        $revAccount = $this->createAccount(AccountTypeEnum::Revenue);
        $this->service->addLine($version->id, null, $revAccount->id, 1, 1000);

        $this->assertEquals(1, $version->lines()->count());
    }

    public function test_forecast_blocks_balance_sheet_accounts()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $version = $forecast->versions->first();
        
        $assetAccount = $this->createAccount(AccountTypeEnum::Asset);

        $this->expectException(ValidationException::class);
        $this->service->addLine($version->id, null, $assetAccount->id, 1, 1000);
    }

    public function test_only_one_approved_forecast_per_year()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $v1 = $forecast->versions->first();
        $this->service->approveVersion($v1->id, 'sys');

        $v2 = $forecast->versions()->create([
            'version_number' => 2,
            'status' => ForecastVersionStatusEnum::Draft,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->approveVersion($v2->id, 'sys');
    }

    public function test_locked_forecast_is_immutable()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $v1 = $forecast->versions->first();
        $this->service->lockVersion($v1->id, 'sys');

        $account = $this->createAccount(AccountTypeEnum::Expense);

        $this->expectException(ValidationException::class);
        $this->service->addLine($v1->id, null, $account->id, 1, 1000);
    }

    public function test_forecast_variance_is_read_only()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $v1 = $forecast->versions->first();
        $this->service->approveVersion($v1->id, 'sys');

        $initialCount = LedgerBalance::count();
        $variance = $this->varianceService->getVariance($this->propertyId, 2027);
        
        $this->assertEquals($initialCount, LedgerBalance::count());
    }

    public function test_forecast_property_isolation()
    {
        $f1 = $this->service->createForecast($this->propertyId, 2027, 'Prop1');
        $f2 = $this->service->createForecast((string) Str::ulid(), 2027, 'Prop2');

        $this->assertNotEquals($f1->property_id, $f2->property_id);
    }

    public function test_forecast_department_support()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $v1 = $forecast->versions->first();
        $account = $this->createAccount(AccountTypeEnum::Expense);
        $deptId = (string) Str::ulid();

        $line = $this->service->addLine($v1->id, $deptId, $account->id, 1, 1000);
        $this->assertEquals($deptId, $line->department_id);
    }

    public function test_forecast_audit_created()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $v1 = $forecast->versions->first();

        $this->service->submitVersion($v1->id, 'maker123');
        
        $this->assertDatabaseHas('forecast_forecast_approvals', [
            'forecast_version_id' => $v1->id,
            'action_by_id' => 'maker123',
            'action' => 'Submitted',
        ]);
    }

    public function test_forecast_cache_created()
    {
        $forecast = $this->service->createForecast($this->propertyId, 2027, 'Test');
        $v1 = $forecast->versions->first();
        
        $this->service->approveVersion($v1->id, 'sys');
        
        $cacheKey = "forecast:active:{$this->propertyId}:2027";
        $this->assertEquals($v1->id, Cache::get($cacheKey));
    }
}

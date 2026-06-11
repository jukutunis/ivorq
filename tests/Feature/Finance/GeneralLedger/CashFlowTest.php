<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Services\CashFlowService;
use Modules\Finance\GeneralLedger\Services\ProfitLossService;
use Mockery;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;
    protected CashFlowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
        
        $profitLossMock = Mockery::mock(ProfitLossService::class);
        $plDto = new \Modules\Finance\GeneralLedger\DTOs\ProfitLossDTO(
            revenue_lines: [],
            cost_of_sales_lines: [],
            expense_lines: [],
            period_total_revenue: 0.0,
            ytd_total_revenue: 0.0,
            period_total_cost_of_sales: 0.0,
            ytd_total_cost_of_sales: 0.0,
            period_gross_profit: 0.0,
            ytd_gross_profit: 0.0,
            period_total_expense: 0.0,
            ytd_total_expense: 0.0,
            period_net_profit: 0.0,
            ytd_net_profit: 5000.0, // Anchor Net Profit
        );

        $profitLossMock->shouldReceive('generate')->andReturn($plDto);
        $this->app->instance(ProfitLossService::class, $profitLossMock);
        
        $this->service = app(CashFlowService::class);
    }

    protected function createAccount(AccountTypeEnum $type, AccountCategoryEnum $category, bool $isCash = false): Account
    {
        return Account::create([
            'property_id' => $this->propertyId,
            'code' => 'TEST-' . rand(1000, 9999),
            'name' => 'Test Account',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => $type,
            'account_category' => $category,
            'is_cash_equivalent' => $isCash,
        ]);
    }

    protected function createBalance(Account $account, int $year, int $month, float $debit, float $credit): LedgerBalance
    {
        return LedgerBalance::create([
            'property_id' => $account->property_id,
            'account_id' => $account->id,
            'period_year' => $year,
            'period_month' => $month,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'ending_balance' => 0,
        ]);
    }

    public function test_cash_flow_net_profit_anchors_operating_activities()
    {
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals(5000.0, $dto->net_profit);
        $this->assertEquals(5000.0, $dto->net_cash_operating);
    }

    public function test_cash_flow_calculates_asset_increase_as_negative_cash()
    {
        $account = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        // Asset Increase -> Debit
        $this->createBalance($account, 2026, 6, debit: 1000, credit: 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals(-1000.0, $dto->operating_lines[0]->amount);
        $this->assertEquals(4000.0, $dto->net_cash_operating); // 5000 NP - 1000
    }

    public function test_cash_flow_calculates_liability_increase_as_positive_cash()
    {
        $account = $this->createAccount(AccountTypeEnum::Liability, AccountCategoryEnum::CurrentLiability);
        // Liability Increase -> Credit
        $this->createBalance($account, 2026, 6, debit: 0, credit: 2000);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals(2000.0, $dto->operating_lines[0]->amount);
        $this->assertEquals(7000.0, $dto->net_cash_operating); // 5000 NP + 2000
    }

    public function test_cash_flow_validates_opening_plus_change_equals_closing()
    {
        $cash = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset, true);
        
        // Opening cash in prior year
        $this->createBalance($cash, 2025, 12, debit: 10000, credit: 0);

        // Net change in current year (Cash Increase)
        // Note: For actual test, cash balances are purely read.
        $this->createBalance($cash, 2026, 6, debit: 5000, credit: 0);
        
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals(10000.0, $dto->opening_cash);
        $this->assertEquals(15000.0, $dto->closing_cash);
        $this->assertEquals(5000.0, $dto->net_cash_change);
        $this->assertTrue($dto->balanced);
    }

    public function test_cash_flow_routes_categories_to_correct_sections()
    {
        $fixed = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::FixedAsset);
        $this->createBalance($fixed, 2026, 6, debit: 1000, credit: 0); // Investing: -1000

        $loan = $this->createAccount(AccountTypeEnum::Liability, AccountCategoryEnum::LongTermLiability);
        $this->createBalance($loan, 2026, 6, debit: 0, credit: 3000); // Financing: +3000

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        
        $this->assertCount(1, $dto->investing_lines);
        $this->assertEquals(-1000.0, $dto->net_cash_investing);
        
        $this->assertCount(1, $dto->financing_lines);
        $this->assertEquals(3000.0, $dto->net_cash_financing);
    }

    public function test_cash_flow_excludes_cash_equivalents_from_adjustments()
    {
        $cash = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset, true);
        $this->createBalance($cash, 2026, 6, debit: 1000, credit: 0);
        
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        
        $this->assertEmpty($dto->operating_lines);
    }

    public function test_cash_flow_enforces_property_isolation()
    {
        $otherProperty = (string) Str::ulid();
        $account = Account::create([
            'property_id' => $otherProperty,
            'code' => 'O-1000',
            'name' => 'Other Prop Asset',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Asset,
            'account_category' => AccountCategoryEnum::CurrentAsset,
            'is_cash_equivalent' => false,
        ]);
        
        $this->createBalance($account, 2026, 6, debit: 1000, credit: 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEmpty($dto->operating_lines);
    }

    public function test_cash_flow_does_not_write_to_database()
    {
        $initialCount = LedgerBalance::count();
        $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals($initialCount, LedgerBalance::count());
    }

    public function test_cash_flow_uses_ytd_method()
    {
        $account = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $this->createBalance($account, 2026, 1, debit: 100, credit: 0);
        $this->createBalance($account, 2026, 6, debit: 200, credit: 0);
        
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals(-300.0, $dto->operating_lines[0]->amount);
    }

    public function test_cash_flow_excludes_equity_to_prevent_net_profit_double_counting()
    {
        $equity = $this->createAccount(AccountTypeEnum::Equity, AccountCategoryEnum::Equity);
        $this->createBalance($equity, 2026, 6, debit: 0, credit: 5000);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        
        $this->assertEmpty($dto->financing_lines); // Equity excluded
    }
}

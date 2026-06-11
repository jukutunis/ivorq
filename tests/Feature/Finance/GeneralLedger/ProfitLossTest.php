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
use Modules\Finance\GeneralLedger\Services\ProfitLossService;

class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    protected ProfitLossService $service;
    protected string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProfitLossService();
        $this->propertyId = (string) Str::ulid();
    }

    protected function createAccount(string $code, NormalBalanceEnum $balance, AccountTypeEnum $type, ?string $propertyId = null): Account
    {
        $category = match ($type) {
            AccountTypeEnum::Asset => AccountCategoryEnum::CurrentAsset,
            AccountTypeEnum::Liability => AccountCategoryEnum::CurrentLiability,
            AccountTypeEnum::Equity => AccountCategoryEnum::Equity,
            AccountTypeEnum::Revenue => AccountCategoryEnum::Revenue,
            AccountTypeEnum::CostOfSales => AccountCategoryEnum::CostOfSales,
            AccountTypeEnum::Expense => AccountCategoryEnum::Expense,
            AccountTypeEnum::Statistical => AccountCategoryEnum::Statistical,
            default => AccountCategoryEnum::Expense,
        };

        return Account::create([
            'property_id' => $propertyId ?? $this->propertyId,
            'code' => $code,
            'name' => "Account $code",
            'normal_balance' => $balance,
            'account_type' => $type,
            'account_category' => $category,
        ]);
    }

    protected function createBalance(string $accountId, int $year, int $month, float $debit, float $credit, ?string $propertyId = null): LedgerBalance
    {
        return LedgerBalance::create([
            'property_id' => $propertyId ?? $this->propertyId,
            'account_id' => $accountId,
            'period_year' => $year,
            'period_month' => $month,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'ending_balance' => 0, // Not used by P&L direct generation
        ]);
    }

    public function test_profit_loss_calculates_correct_net_profit()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        $cos = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::CostOfSales);
        $expense = $this->createAccount('6000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);

        // Revenue: 1000 credit
        $this->createBalance($revenue->id, 2026, 6, 0, 1000);
        // COS: 400 debit
        $this->createBalance($cos->id, 2026, 6, 400, 0);
        // Expense: 200 debit
        $this->createBalance($expense->id, 2026, 6, 200, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEquals(1000, $dto->period_total_revenue);
        $this->assertEquals(400, $dto->period_total_cost_of_sales);
        $this->assertEquals(600, $dto->period_gross_profit); // 1000 - 400
        $this->assertEquals(200, $dto->period_total_expense);
        $this->assertEquals(400, $dto->period_net_profit); // 600 - 200
    }

    public function test_profit_loss_revenue_is_displayed_positive()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        // Revenue should normally be a credit. The formula is credit - debit.
        $this->createBalance($revenue->id, 2026, 6, 0, 5000); // 5000 credit

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->revenue_lines);
        $this->assertEquals(5000, $dto->revenue_lines[0]->period_amount);
        $this->assertEquals(5000, $dto->period_total_revenue);
    }

    public function test_profit_loss_expenses_are_displayed_positive()
    {
        $expense = $this->createAccount('6000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);
        // Expense should normally be a debit. The formula is debit - credit.
        $this->createBalance($expense->id, 2026, 6, 3000, 0); // 3000 debit

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->expense_lines);
        $this->assertEquals(3000, $dto->expense_lines[0]->period_amount);
        $this->assertEquals(3000, $dto->period_total_expense);
    }

    public function test_profit_loss_excludes_balance_sheet_and_statistical_accounts()
    {
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);
        $liability = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);
        $stat = $this->createAccount('STAT1', NormalBalanceEnum::Debit, AccountTypeEnum::Statistical);

        $this->createBalance($asset->id, 2026, 6, 1000, 0);
        $this->createBalance($liability->id, 2026, 6, 0, 1000);
        $this->createBalance($stat->id, 2026, 6, 500, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEmpty($dto->revenue_lines);
        $this->assertEmpty($dto->cost_of_sales_lines);
        $this->assertEmpty($dto->expense_lines);
        $this->assertEquals(0, $dto->period_net_profit);
    }

    public function test_profit_loss_enforces_property_isolation()
    {
        $propertyId2 = (string) Str::ulid();
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue, $propertyId2);
        
        $this->createBalance($revenue->id, 2026, 6, 0, 1000, $propertyId2);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEmpty($dto->revenue_lines);
        $this->assertEquals(0, $dto->period_total_revenue);
    }

    public function test_profit_loss_does_not_write_to_database()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        $this->createBalance($revenue->id, 2026, 6, 0, 1000);

        $initialCount = LedgerBalance::count();
        $this->service->generate($this->propertyId, 2026, 6);
        $finalCount = LedgerBalance::count();

        $this->assertEquals($initialCount, $finalCount);
    }

    public function test_profit_loss_returns_period_and_ytd_amounts()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);

        // Month 1 (YTD only)
        $this->createBalance($revenue->id, 2026, 1, 0, 2000);
        // Month 6 (Period and YTD)
        $this->createBalance($revenue->id, 2026, 6, 0, 1000);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->revenue_lines);
        $this->assertEquals(1000, $dto->revenue_lines[0]->period_amount);
        $this->assertEquals(3000, $dto->revenue_lines[0]->ytd_amount); // 2000 + 1000

        $this->assertEquals(1000, $dto->period_total_revenue);
        $this->assertEquals(3000, $dto->ytd_total_revenue);
    }

    public function test_contra_revenue_reduces_total_revenue()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        // Contra-revenue account (e.g. Sales Discounts) - still classified as Revenue but with Debit balance
        $contraRev = $this->createAccount('4100', NormalBalanceEnum::Debit, AccountTypeEnum::Revenue);

        $this->createBalance($revenue->id, 2026, 6, 0, 5000); // 5000 credit
        $this->createBalance($contraRev->id, 2026, 6, 500, 0); // 500 debit

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(2, $dto->revenue_lines);
        
        $this->assertEquals(5000, $dto->revenue_lines[0]->period_amount);
        $this->assertEquals(-500, $dto->revenue_lines[1]->period_amount); // credit(0) - debit(500) = -500

        $this->assertEquals(4500, $dto->period_total_revenue);
    }
}

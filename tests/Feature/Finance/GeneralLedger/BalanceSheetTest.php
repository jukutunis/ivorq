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
use Modules\Finance\GeneralLedger\Services\BalanceSheetService;
use Modules\Finance\GeneralLedger\Services\ProfitLossService;

class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    protected BalanceSheetService $service;
    protected string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BalanceSheetService(new ProfitLossService());
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
            'ending_balance' => 0,
        ]);
    }

    public function test_balance_sheet_calculates_correct_totals()
    {
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);
        $liability = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);
        $equity = $this->createAccount('3000', NormalBalanceEnum::Credit, AccountTypeEnum::Equity);

        $this->createBalance($asset->id, 2026, 6, 10000, 0); // Asset 10000
        $this->createBalance($liability->id, 2026, 6, 0, 4000); // Liab 4000
        $this->createBalance($equity->id, 2026, 6, 0, 6000); // Equity 6000

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEquals(10000, $dto->total_assets);
        $this->assertEquals(4000, $dto->total_liabilities);
        $this->assertEquals(6000, $dto->total_equity); // 6000 + 0 CYE + 0 PYE
    }

    public function test_balance_sheet_injects_current_year_earnings_correctly()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        $expense = $this->createAccount('6000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);

        // Revenue this year = 5000
        $this->createBalance($revenue->id, 2026, 6, 0, 5000);
        // Expense this year = 2000
        $this->createBalance($expense->id, 2026, 6, 2000, 0);

        // CYE should be 5000 - 2000 = 3000
        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEquals(3000, $dto->current_year_earnings);
        $this->assertEquals(3000, $dto->total_equity); // Base 0 + 3000 CYE
    }

    public function test_balance_sheet_calculates_prior_year_retained_earnings_dynamically()
    {
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        $expense = $this->createAccount('6000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);

        // Previous year revenue = 8000
        $this->createBalance($revenue->id, 2025, 12, 0, 8000);
        // Previous year expense = 3000
        $this->createBalance($expense->id, 2025, 12, 3000, 0);

        // Current year revenue = 1000
        $this->createBalance($revenue->id, 2026, 6, 0, 1000);

        // Prior Year Earnings should be 8000 - 3000 = 5000
        // Current Year Earnings should be 1000
        // Total Equity = 5000 + 1000 = 6000

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEquals(5000, $dto->prior_year_retained_earnings);
        $this->assertEquals(1000, $dto->current_year_earnings);
        $this->assertEquals(6000, $dto->total_equity);
    }

    public function test_balance_sheet_is_balanced()
    {
        // 1. Prior Year Earnings (Retained Earnings)
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);
        $this->createBalance($revenue->id, 2025, 12, 0, 5000); // 5000 PYE

        // 2. Current Year Earnings
        $this->createBalance($revenue->id, 2026, 6, 0, 2000); // 2000 CYE

        // 3. Equity Account
        $equity = $this->createAccount('3000', NormalBalanceEnum::Credit, AccountTypeEnum::Equity);
        $this->createBalance($equity->id, 2025, 1, 0, 3000); // 3000 Base Equity

        // 4. Liabilities
        $liability = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);
        $this->createBalance($liability->id, 2026, 6, 0, 4000); // 4000 Liab

        // 5. Assets
        // Total Equity = 5000 PYE + 2000 CYE + 3000 Base = 10000
        // Total Liab = 4000
        // Total Assets must be 14000
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);
        $this->createBalance($asset->id, 2026, 6, 14000, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEquals(14000, $dto->total_assets);
        $this->assertEquals(4000, $dto->total_liabilities);
        $this->assertEquals(10000, $dto->total_equity);
        $this->assertTrue($dto->balanced);
    }

    public function test_balance_sheet_excludes_pnl_and_statistical_accounts()
    {
        $stat = $this->createAccount('STAT1', NormalBalanceEnum::Debit, AccountTypeEnum::Statistical);
        $revenue = $this->createAccount('4000', NormalBalanceEnum::Credit, AccountTypeEnum::Revenue);

        $this->createBalance($stat->id, 2026, 6, 500, 0);
        $this->createBalance($revenue->id, 2026, 6, 0, 1000); // Will show up in CYE, not directly in lines

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEmpty($dto->asset_lines);
        $this->assertEmpty($dto->liability_lines);
        $this->assertEmpty($dto->equity_lines);
    }

    public function test_balance_sheet_enforces_property_isolation()
    {
        $propertyId2 = (string) Str::ulid();
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset, $propertyId2);
        
        $this->createBalance($asset->id, 2026, 6, 10000, 0, $propertyId2);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertEquals(0, $dto->total_assets);
        $this->assertEmpty($dto->asset_lines);
    }

    public function test_balance_sheet_does_not_write_to_database()
    {
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);
        $this->createBalance($asset->id, 2026, 6, 1000, 0);

        $initialCount = LedgerBalance::count();
        $this->service->generate($this->propertyId, 2026, 6);
        $finalCount = LedgerBalance::count();

        $this->assertEquals($initialCount, $finalCount);
    }
}

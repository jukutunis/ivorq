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
use Modules\Finance\GeneralLedger\Services\TrialBalanceService;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected TrialBalanceService $service;
    protected string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrialBalanceService();
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
            'ending_balance' => 0, // Ignored by dynamic service anyway
        ]);
    }

    public function test_trial_balance_calculates_correct_opening_balance()
    {
        $account = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);

        // Previous year balances
        $this->createBalance($account->id, 2025, 12, 1000, 200); // net 800
        // Previous month balance
        $this->createBalance($account->id, 2026, 5, 500, 100); // net 400

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->lines);
        $this->assertEquals(1200, $dto->lines[0]->opening_balance); // 800 + 400
        $this->assertEquals(0, $dto->lines[0]->debit_activity);
        $this->assertEquals(0, $dto->lines[0]->credit_activity);
        $this->assertEquals(1200, $dto->lines[0]->ending_balance);
    }

    public function test_trial_balance_calculates_correct_period_activity()
    {
        $account = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);

        // Current month balance
        $this->createBalance($account->id, 2026, 6, 300, 150);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->lines);
        $this->assertEquals(0, $dto->lines[0]->opening_balance);
        $this->assertEquals(300, $dto->lines[0]->debit_activity);
        $this->assertEquals(150, $dto->lines[0]->credit_activity);
        $this->assertEquals(150, $dto->lines[0]->ending_balance); // 300 - 150
        $this->assertEquals(300, $dto->total_debit);
        $this->assertEquals(150, $dto->total_credit);
        $this->assertFalse($dto->balanced); // We only added one side
    }

    public function test_trial_balance_excludes_statistical_accounts()
    {
        $account = $this->createAccount('STAT1', NormalBalanceEnum::Debit, AccountTypeEnum::Statistical);
        $this->createBalance($account->id, 2026, 6, 100, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(0, $dto->lines);
    }

    public function test_trial_balance_enforces_property_isolation()
    {
        $propertyId2 = (string) Str::ulid();
        $account = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset, $propertyId2);
        $this->createBalance($account->id, 2026, 6, 100, 0, $propertyId2);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(0, $dto->lines);
    }

    public function test_trial_balance_totals_are_balanced()
    {
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);
        $liability = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $this->createBalance($asset->id, 2026, 6, 1000, 0);
        $this->createBalance($liability->id, 2026, 6, 0, 1000);

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertTrue($dto->balanced);
        $this->assertEquals(1000, $dto->total_debit);
        $this->assertEquals(1000, $dto->total_credit);
    }

    public function test_trial_balance_does_not_write_to_database()
    {
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);
        $this->createBalance($asset->id, 2026, 6, 1000, 0);

        $initialCount = LedgerBalance::count();
        $this->service->generate($this->propertyId, 2026, 6);
        $finalCount = LedgerBalance::count();

        $this->assertEquals($initialCount, $finalCount);
    }

    public function test_pnl_accounts_reset_opening_balance_at_fiscal_year_start()
    {
        $expense = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);

        // Previous year balances (should be excluded from opening balance for P&L)
        $this->createBalance($expense->id, 2025, 12, 1000, 0); 
        
        // Current year, previous month balance (should be included)
        $this->createBalance($expense->id, 2026, 5, 500, 0); 

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->lines);
        $this->assertEquals(500, $dto->lines[0]->opening_balance); // Only 2026 balance
    }

    public function test_balance_sheet_accounts_carry_forward_opening_balance()
    {
        $asset = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);

        // Previous year balances (should be INCLUDED from opening balance for Balance Sheet)
        $this->createBalance($asset->id, 2025, 12, 1000, 0); 
        
        // Current year, previous month balance (should be included)
        $this->createBalance($asset->id, 2026, 5, 500, 0); 

        $dto = $this->service->generate($this->propertyId, 2026, 6);

        $this->assertCount(1, $dto->lines);
        $this->assertEquals(1500, $dto->lines[0]->opening_balance); // 2025 + 2026 balances
    }
}

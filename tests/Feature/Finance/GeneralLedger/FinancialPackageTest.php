<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\Finance\GeneralLedger\Services\FinancialPackageService;
use Modules\Finance\GeneralLedger\Services\PeriodControlService;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Models\FinancialPackageSnapshot;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\PackageStatusEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;

class FinancialPackageTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;
    protected FinancialPackageService $service;
    protected PeriodControlService $periodControlService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
        $this->service = app(FinancialPackageService::class);
        $this->periodControlService = app(PeriodControlService::class);
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

    public function test_package_orchestrates_all_reports()
    {
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        
        $this->assertNotNull($dto->trial_balance);
        $this->assertNotNull($dto->profit_loss);
        $this->assertNotNull($dto->balance_sheet);
        $this->assertNotNull($dto->cash_flow);
    }

    public function test_package_validates_trial_balance()
    {
        $asset = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $this->createBalance($asset, 2026, 6, 100, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertFalse($dto->validations['trial_balance_valid']);
        $this->assertEquals(PackageStatusEnum::Invalid, $dto->status);
    }

    public function test_package_validates_balance_sheet()
    {
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertTrue($dto->validations['balance_sheet_valid']);
    }

    public function test_package_validates_cash_flow()
    {
        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertTrue($dto->validations['cash_flow_valid']);
    }

    public function test_package_validates_net_profit_cross_report()
    {
        $revenue = $this->createAccount(AccountTypeEnum::Revenue, AccountCategoryEnum::Revenue);
        $asset = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);

        $this->createBalance($revenue, 2026, 6, 0, 5000);
        $this->createBalance($asset, 2026, 6, 5000, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        
        $this->assertTrue($dto->validations['net_profit_cross_report_valid']);
        $this->assertEquals(PackageStatusEnum::Valid, $dto->status);
    }

    public function test_package_validates_cash_balance_cross_report()
    {
        $cash = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset, true);
        $equity = $this->createAccount(AccountTypeEnum::Equity, AccountCategoryEnum::Equity);

        $this->createBalance($cash, 2026, 6, 1000, 0);
        $this->createBalance($equity, 2026, 6, 0, 1000);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        
        $this->assertTrue($dto->validations['cash_balance_cross_report_valid']);
    }

    public function test_package_generates_snapshot_for_closed_period()
    {
        $this->periodControlService->isOpen($this->propertyId, 2026, 6);
        $userId = (string) Str::ulid();
        $this->periodControlService->close($this->propertyId, 2026, 6, $userId);

        $this->assertDatabaseMissing('gl_financial_package_snapshots', [
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
        ]);

        $this->service->generate($this->propertyId, 2026, 6);

        $this->assertDatabaseHas('gl_financial_package_snapshots', [
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
            'package_status' => PackageStatusEnum::Valid->value,
        ]);
    }

    public function test_reopen_invalidates_snapshot()
    {
        $this->periodControlService->isOpen($this->propertyId, 2026, 6);
        $userId = (string) Str::ulid();
        $this->periodControlService->close($this->propertyId, 2026, 6, $userId);

        $this->service->generate($this->propertyId, 2026, 6);

        $this->assertDatabaseHas('gl_financial_package_snapshots', [
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
        ]);

        $this->periodControlService->reopen($this->propertyId, 2026, 6, $userId, 'Audit fix');

        $this->assertDatabaseMissing('gl_financial_package_snapshots', [
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 6,
        ]);
    }

    public function test_package_enforces_property_isolation()
    {
        $otherProperty = (string) Str::ulid();
        $asset = Account::create([
            'property_id' => $otherProperty,
            'code' => 'O-1000',
            'name' => 'Other Prop Asset',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Asset,
            'account_category' => AccountCategoryEnum::CurrentAsset,
            'is_cash_equivalent' => false,
        ]);
        
        $this->createBalance($asset, 2026, 6, 1000, 0);

        $dto = $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals(0, $dto->trial_balance->total_debit);
    }

    public function test_package_is_read_only()
    {
        $initialCount = LedgerBalance::count();
        $this->service->generate($this->propertyId, 2026, 6);
        $this->assertEquals($initialCount, LedgerBalance::count());
    }
}

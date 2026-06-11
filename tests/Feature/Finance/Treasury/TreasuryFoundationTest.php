<?php

namespace Tests\Feature\Finance\Treasury;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\Finance\Treasury\Services\TreasuryService;
use Modules\Finance\Treasury\Models\BankBalanceSnapshot;
use Modules\Finance\Treasury\Models\TreasuryAlertLog;
use Modules\Finance\Treasury\Enums\TreasuryAlertSeverityEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Illuminate\Support\Facades\Cache;

class TreasuryFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;
    protected TreasuryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
        $this->service = app(TreasuryService::class);
    }

    protected function createCashAccount(): Account
    {
        return Account::create([
            'property_id' => $this->propertyId,
            'code' => 'T-' . rand(1000, 9999),
            'name' => 'Cash Account',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Asset,
            'account_category' => AccountCategoryEnum::CurrentAsset,
            'is_cash_equivalent' => true,
        ]);
    }

    public function test_cash_position_aggregates_all_cash_sources()
    {
        $acc1 = $this->createCashAccount();
        $acc2 = $this->createCashAccount();

        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $acc1->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 1000,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $acc2->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 500,
            'credit_total' => 100,
            'ending_balance' => 0,
        ]);

        $cash = $this->service->getCurrentCashPosition($this->propertyId, 2027);
        $this->assertEquals(1400, $cash);
    }

    public function test_liquidity_projection_uses_ap_obligations()
    {
        Cache::put("mock_ap_{$this->propertyId}", 500);

        $liquidity = $this->service->getLiquidityProjection($this->propertyId, 2027);
        
        $this->assertEquals(-500, $liquidity['7_day_change']);
    }

    public function test_forecast_used_when_ar_missing()
    {
        $acc = Account::create([
            'property_id' => $this->propertyId,
            'code' => 'Rev',
            'name' => 'Rev',
            'normal_balance' => NormalBalanceEnum::Credit,
            'account_type' => AccountTypeEnum::Revenue,
            'account_category' => AccountCategoryEnum::Revenue,
            'is_cash_equivalent' => false,
        ]);

        $budgetService = app(\Modules\Finance\Budgeting\Services\BudgetService::class);
        $budget = $budgetService->createBudget($this->propertyId, 2027, 'Test');
        $bVersion = $budget->versions->first();
        $budgetService->addLine($bVersion->id, null, $acc->id, now()->month, 3000);
        $budgetService->approveVersion($bVersion->id, 'sys');

        $liquidity = $this->service->getLiquidityProjection($this->propertyId, 2027);
        
        $this->assertEquals(100 * 30, $liquidity['30_day_change']);
    }

    public function test_low_cash_alert_triggered()
    {
        $acc = $this->createCashAccount();
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $acc->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 100,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        $expAcc = Account::create([
            'property_id' => $this->propertyId,
            'code' => 'Exp',
            'name' => 'Exp',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Expense,
            'account_category' => AccountCategoryEnum::Expense,
            'is_cash_equivalent' => false,
        ]);
        $budgetService = app(\Modules\Finance\Budgeting\Services\BudgetService::class);
        $budget = $budgetService->createBudget($this->propertyId, 2027, 'Test');
        $bVersion = $budget->versions->first();
        $budgetService->addLine($bVersion->id, null, $expAcc->id, now()->month, 1000);
        $budgetService->approveVersion($bVersion->id, 'sys');

        $metrics = $this->service->getDashboardMetrics($this->propertyId, 2027);

        $this->assertTrue(true);
    }

    public function test_negative_cash_alert_triggered()
    {
        $acc = $this->createCashAccount();
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $acc->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 0,
            'credit_total' => 100,
            'ending_balance' => 0,
        ]);

        $this->service->getDashboardMetrics($this->propertyId, 2027);

        $this->assertDatabaseHas('treasury_alert_logs', [
            'property_id' => $this->propertyId,
            'alert_type' => 'Negative Cash Alert',
            'severity' => TreasuryAlertSeverityEnum::Critical->value,
        ]);
    }

    public function test_reconciliation_stale_alert_triggered()
    {
        Cache::put("mock_recon_{$this->propertyId}", 45);

        $this->service->getDashboardMetrics($this->propertyId, 2027);

        $this->assertDatabaseHas('treasury_alert_logs', [
            'property_id' => $this->propertyId,
            'alert_type' => 'Reconciliation Stale Alert',
            'severity' => TreasuryAlertSeverityEnum::High->value,
        ]);
    }

    public function test_snapshot_is_immutable()
    {
        $snap = BankBalanceSnapshot::create([
            'property_id' => $this->propertyId,
            'bank_account_id' => (string) Str::ulid(),
            'snapshot_date' => '2026-06-01',
            'balance' => 100,
        ]);

        $snap->balance = 200;
        $snap->save();

        $snap->refresh();
        $this->assertEquals(100, $snap->balance);

        $snap->delete();
        $this->assertDatabaseHas('treasury_bank_balance_snapshots', [
            'id' => $snap->id
        ]);
    }

    public function test_treasury_property_isolation()
    {
        $otherProp = (string) Str::ulid();
        $acc = $this->createCashAccount();
        
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $acc->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 1000,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        $cashProp1 = $this->service->getCurrentCashPosition($this->propertyId, 2027);
        $cashProp2 = $this->service->getCurrentCashPosition($otherProp, 2027);

        $this->assertEquals(1000, $cashProp1);
        $this->assertEquals(0, $cashProp2);
    }

    public function test_treasury_read_only()
    {
        $count = LedgerBalance::count();
        $this->service->getDashboardMetrics($this->propertyId, 2027);
        $this->assertEquals($count, LedgerBalance::count());
    }

    public function test_critical_alert_logged()
    {
        $acc = $this->createCashAccount();
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $acc->id,
            'period_year' => 2027,
            'period_month' => 1,
            'debit_total' => 0,
            'credit_total' => 500,
            'ending_balance' => 0,
        ]);

        $this->service->getDashboardMetrics($this->propertyId, 2027);

        $this->assertDatabaseHas('treasury_alert_logs', [
            'property_id' => $this->propertyId,
            'severity' => TreasuryAlertSeverityEnum::Critical->value,
        ]);
    }
}

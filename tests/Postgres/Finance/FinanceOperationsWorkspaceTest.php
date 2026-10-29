<?php

namespace Tests\Postgres\Finance;

use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Finance\Budgeting\Models\Budget;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\Budgeting\Services\BudgetService;
use Modules\Finance\Forecasting\Models\Forecast;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\Treasury\Models\TreasuryAlertLog;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestDepositRefundArData;
use Tests\PostgresTestCase;

class FinanceOperationsWorkspaceTest extends PostgresTestCase
{
    use CreatesGuestDepositRefundArData;
    use RefreshDatabase;

    private int $transferSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGlfCFixture();
        $this->grant($this->glfActor, [
            'accounting.ar.view',
            'finance.treasury.view',
            'budget.view', 'budget.create', 'budget.edit', 'budget.submit',
            'forecast.view', 'forecast.create', 'forecast.edit', 'forecast.submit',
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_permission_authorities_are_registered_without_role_seeder_drift(): void
    {
        $this->seed(PermissionSeeder::class);
        foreach ([
            'accounting.ar.view', 'finance.treasury.view',
            'budget.view', 'budget.create', 'budget.edit', 'budget.submit', 'budget.approve',
            'forecast.view', 'forecast.create', 'forecast.edit', 'forecast.submit', 'forecast.approve',
        ] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'web']);
        }

        $this->assertSame(
            '8519c4618ec614aafb94f2611753bfbad5a147ff',
            sha1('blob '.strlen(file_get_contents(base_path('Modules/Foundation/Authorization/database/seeders/RoleSeeder.php')))."\0".file_get_contents(base_path('Modules/Foundation/Authorization/database/seeders/RoleSeeder.php'))),
        );
    }

    public function test_ar_workspace_counts_only_accepted_unreversed_transfer_evidence(): void
    {
        $accepted = $this->transferEvidence(GuestArTransferStatusEnum::Accepted, '80.00');
        $this->transferEvidence(GuestArTransferStatusEnum::Requested, '40.00');
        $this->transferEvidence(GuestArTransferStatusEnum::Rejected, '60.00');
        $this->transferEvidence(GuestArTransferStatusEnum::Reversed, '90.00');

        $props = $this->acting()->get('/finance/accounts-receivable')->assertOk()->inertiaProps();
        $items = collect($props['workspace']['accounts_receivable']['items']);

        $this->assertSame([$accepted->id], $items->pluck('id')->all());
        $this->assertSame('80.00', $items->first()['amount']);
        $this->assertSame('ACCEPTED_UNREVERSED_GUEST_AR_TRANSFER_EVIDENCE', $props['workspace']['authority']['ar']);
    }

    public function test_treasury_projection_is_property_scoped_and_has_no_read_side_effects(): void
    {
        $visible = $this->cashAccount($this->glfProperty->id, 'CASH-A');
        $hidden = $this->cashAccount($this->glfOtherProperty->id, 'CASH-B');
        $this->balance($this->glfProperty->id, $visible->id, 1250);
        $this->balance($this->glfOtherProperty->id, $hidden->id, 9000);
        $before = TreasuryAlertLog::count();

        $props = $this->acting()->get('/finance/treasury?year='.now()->year)->assertOk()->inertiaProps();

        $this->assertEquals(1250.0, $props['workspace']['treasury']['cash_position']);
        $this->assertSame(0, $props['workspace']['treasury']['read_side_effects']);
        $this->assertSame($before, TreasuryAlertLog::count());
    }

    public function test_budget_workflow_uses_server_property_and_permission_separated_approval(): void
    {
        $account = $this->planningAccount($this->glfProperty->id, 'REV-A');
        $otherDepartment = Department::create([
            'property_id' => $this->glfOtherProperty->id,
            'name' => 'Other Property Finance',
            'code' => 'FIN',
            'is_active' => true,
        ]);
        $this->acting()->post(route('finance.budgeting.store'), ['fiscal_year' => 2098, 'name' => 'Property budget'])->assertRedirect();
        $budget = Budget::where('property_id', $this->glfProperty->id)->where('fiscal_year', 2098)->firstOrFail();
        $version = $budget->versions()->firstOrFail();

        $this->acting()->post(route('finance.budgeting.lines.store', $version), [
            'department_id' => $otherDepartment->id, 'account_id' => $account->id, 'period_month' => 1, 'amount' => '5000.00',
        ])->assertNotFound();
        $this->acting()->post(route('finance.budgeting.lines.store', $version), [
            'account_id' => $account->id, 'period_month' => 1, 'amount' => '5000.00',
        ])->assertRedirect();
        $this->acting()->post(route('finance.budgeting.versions.transition', $version), ['action' => 'submit'])->assertRedirect();
        $this->acting()->post(route('finance.budgeting.lines.store', $version), [
            'account_id' => $account->id, 'period_month' => 2, 'amount' => '1.00',
        ])->assertStatus(409);

        $this->acting()->post(route('finance.budgeting.versions.transition', $version), ['action' => 'approve'])->assertForbidden();
        $this->grant($this->glfActor, ['budget.approve']);
        $this->acting()->post(route('finance.budgeting.versions.transition', $version), ['action' => 'approve'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Budget version updated.');

        $this->assertSame('Locked', $version->fresh()->status->value);
        $this->assertSame('5000.0000', (string) BudgetLine::where('budget_version_id', $version->id)->value('amount'));
        $this->assertSame($this->glfProperty->id, $budget->property_id);
    }

    public function test_forecast_workflow_is_property_scoped_permission_separated_and_immutable_after_approval(): void
    {
        $account = $this->planningAccount($this->glfProperty->id, 'REV-F');
        $this->acting()->post(route('finance.forecasting.store'), ['fiscal_year' => 2096, 'name' => 'Operating forecast'])->assertRedirect();
        $forecast = Forecast::where('property_id', $this->glfProperty->id)->where('fiscal_year', 2096)->firstOrFail();
        $version = $forecast->versions()->firstOrFail();

        $this->acting()->post(route('finance.forecasting.lines.store', $version), [
            'account_id' => $account->id, 'period_month' => 4, 'amount' => '3200.00',
        ])->assertRedirect();
        $this->acting()->post(route('finance.forecasting.versions.transition', $version), ['action' => 'submit'])->assertRedirect();
        $this->acting()->post(route('finance.forecasting.versions.transition', $version), ['action' => 'approve'])->assertForbidden();

        $this->grant($this->glfActor, ['forecast.approve']);
        $this->acting()->post(route('finance.forecasting.versions.transition', $version), ['action' => 'approve'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Forecast version updated.');
        $this->acting()->post(route('finance.forecasting.lines.store', $version), [
            'account_id' => $account->id, 'period_month' => 5, 'amount' => '1.00',
        ])->assertStatus(409);

        $this->assertSame('Locked', $version->fresh()->status->value);
        $this->assertSame($this->glfProperty->id, $forecast->property_id);
    }

    public function test_cross_property_planning_version_fails_closed(): void
    {
        $other = app(BudgetService::class)
            ->createBudget($this->glfOtherProperty->id, 2097, 'Other Property');
        $otherVersion = $other->versions()->firstOrFail();

        $this->acting()->post(route('finance.budgeting.versions.transition', $otherVersion), ['action' => 'submit'])->assertNotFound();
        $this->assertSame('Draft', $otherVersion->fresh()->status->value);
    }

    public function test_finance_workspace_omits_unauthorized_capabilities_and_contains_no_fixture_authority(): void
    {
        $unauthorized = UserFactory::new()->withProperty($this->glfProperty)->create(['is_active' => true]);
        $this->acting($unauthorized)->get('/finance/revenue-cash')->assertForbidden();

        $arOnly = UserFactory::new()->withProperty($this->glfProperty)->create(['is_active' => true]);
        $this->grant($arOnly, ['accounting.ar.view']);
        $props = $this->acting($arOnly)->get('/finance/revenue-cash')->assertOk()->inertiaProps();
        $this->assertTrue($props['capabilities']['can_view_ar']);
        $this->assertFalse($props['capabilities']['can_view_treasury']);
        $this->assertNull($props['workspace']['treasury']);
        $this->assertNull($props['workspace']['budget']);
        $this->assertNull($props['workspace']['forecast']);

        $source = file_get_contents(resource_path('js/Pages/Ivorq/Finance/FinanceWorkspace.tsx'));
        $this->assertStringNotContainsString('financeData', $source);
        $this->assertStringContainsString('Every value on this page is projected server-side', $source);
    }

    private function transferEvidence(GuestArTransferStatusEnum $status, string $amount): GuestArTransferRequest
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->makeGlfFolio($reservation, $reservation->primaryGuest);
        $sequence = ++$this->transferSequence;
        $request = new GuestArTransferRequest;
        $request->forceFill([
            'property_id' => $this->glfProperty->id,
            'transfer_number' => 'FIN-P03-'.$sequence,
            'folio_id' => $folio->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->primary_guest_id,
            'currency' => 'USD',
            'amount' => $amount,
            'lifecycle_status' => $status->value,
            'request_reason_code' => 'DIRECT_BILL',
            'request_idempotency_key' => 'fin-p03-request-'.$sequence,
            'requested_at' => now()->subDays($sequence),
            'requested_by' => $this->glfActor->id,
            'source_snapshot' => ['test' => 'FIN-P03', 'sequence' => $sequence],
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
        ])->save();

        if ($status === GuestArTransferStatusEnum::Requested) {
            return $request->fresh();
        }

        $terminalType = $status === GuestArTransferStatusEnum::Rejected
            ? GuestArTransferDecisionTypeEnum::Rejected
            : GuestArTransferDecisionTypeEnum::Accepted;
        $terminal = $this->decision($request, $terminalType, 'fin-p03-terminal-'.$sequence);
        if ($status === GuestArTransferStatusEnum::Reversed) {
            $this->decision($request, GuestArTransferDecisionTypeEnum::Reversed, 'fin-p03-reversal-'.$sequence, $terminal->id);
        }

        return $request->fresh();
    }

    private function decision(GuestArTransferRequest $request, GuestArTransferDecisionTypeEnum $type, string $key, ?string $reverses = null): GuestArTransferDecision
    {
        $decision = new GuestArTransferDecision;
        $decision->forceFill([
            'property_id' => $request->property_id,
            'guest_ar_transfer_request_id' => $request->id,
            'decision_type' => $type->value,
            'reverses_decision_id' => $reverses,
            'reason_code' => 'FIN_P03_TEST',
            'decision_idempotency_key' => $key,
            'decided_at' => now(),
            'decided_by' => $this->glfActor->id,
            'source_snapshot' => ['transfer_request_id' => $request->id, 'decision_type' => $type->value],
            'created_at' => now(),
        ])->save();

        return $decision->fresh();
    }

    private function grant(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($this->glfProperty->id);
        $user->givePermissionTo($permissions);
        $user->unsetRelation('permissions')->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function acting(?User $user = null): static
    {
        app(CurrentPropertyService::class)->setPropertyId($this->glfProperty->id);
        setPermissionsTeamId($this->glfProperty->id);

        return $this->withSession([
            'active_company_id' => $this->glfCompany->id,
            'active_property_id' => $this->glfProperty->id,
            'current_property_id' => $this->glfProperty->id,
        ])->actingAs($user ?? $this->glfActor, 'web');
    }

    private function cashAccount(string $propertyId, string $code): Account
    {
        return Account::create([
            'property_id' => $propertyId,
            'code' => $code,
            'name' => $code,
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Asset,
            'account_category' => AccountCategoryEnum::CurrentAsset,
            'is_cash_equivalent' => true,
        ]);
    }

    private function planningAccount(string $propertyId, string $code): Account
    {
        return Account::create([
            'property_id' => $propertyId,
            'code' => $code,
            'name' => $code,
            'normal_balance' => NormalBalanceEnum::Credit,
            'account_type' => AccountTypeEnum::Revenue,
            'account_category' => AccountCategoryEnum::Revenue,
            'is_cash_equivalent' => false,
        ]);
    }

    private function balance(string $propertyId, string $accountId, float $debit): LedgerBalance
    {
        return LedgerBalance::create([
            'property_id' => $propertyId,
            'account_id' => $accountId,
            'period_year' => now()->year,
            'period_month' => 1,
            'debit_total' => $debit,
            'credit_total' => 0,
            'ending_balance' => $debit,
        ]);
    }
}

<?php

namespace Tests\Postgres\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\BudgetApproval;
use Modules\Finance\Budgeting\Services\BudgetService;
use Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum;
use Modules\Finance\Forecasting\Models\ForecastApproval;
use Modules\Finance\Forecasting\Services\ForecastService;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Tests\PostgresTestCase;

class PlanningLifecycleServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_budget_service_enforces_state_checker_and_rejection_revision_history(): void
    {
        $propertyId = $this->id();
        $makerId = $this->id();
        $checkerId = $this->id();
        $account = $this->account($propertyId, 'BUD-R1');
        $service = app(BudgetService::class);
        $budget = $service->createBudget($propertyId, 2091, 'Lifecycle budget', $makerId);
        $version = $budget->versions()->firstOrFail();

        $service->addLine($version->id, null, $account->id, 1, 1250, $makerId);
        $this->assertValidation(fn () => $service->approveVersion($version->id, $checkerId));
        $this->assertValidation(fn () => $service->rejectVersion($version->id, $checkerId));

        $service->submitVersion($version->id, $makerId);
        $this->assertValidation(fn () => $service->addLine($version->id, null, $account->id, 2, 10, $makerId));
        $this->assertValidation(fn () => $service->approveVersion($version->id, $makerId));
        $this->assertValidation(fn () => $service->rejectVersion($version->id, $makerId));
        $service->rejectVersion($version->id, $checkerId, 'Revise plan');

        $revision = $budget->versions()->where('version_number', 2)->firstOrFail();
        $this->assertSame(BudgetVersionStatusEnum::Rejected, $version->fresh()->status);
        $this->assertSame(BudgetVersionStatusEnum::Draft, $revision->status);
        $this->assertSame($checkerId, $revision->created_by);
        $this->assertSame('1250.0000', (string) $version->lines()->value('amount'));
        $this->assertSame('1250.0000', (string) $revision->lines()->value('amount'));
        $this->assertValidation(fn () => $service->addLine($version->id, null, $account->id, 2, 10, $checkerId));

        $service->addLine($revision->id, null, $account->id, 2, 10, $checkerId);
        $this->assertSame(1, $version->lines()->count());
        $this->assertSame(2, $revision->lines()->count());
        $this->assertDatabaseHas('budget_budget_approvals', [
            'budget_version_id' => $version->id,
            'action_by_id' => $checkerId,
            'action' => 'Rejected',
        ]);
    }

    public function test_budget_approval_is_atomic_locked_and_one_per_property_year(): void
    {
        $propertyId = $this->id();
        $makerId = $this->id();
        $checkerId = $this->id();
        $submitterId = $this->id();
        $service = app(BudgetService::class);
        $account = $this->account($propertyId, 'BUD-R2');
        $budget = $service->createBudget($propertyId, 2092, 'Approved budget', $makerId);
        $version = $budget->versions()->firstOrFail();
        $service->addLine($version->id, null, $account->id, 1, 900, $makerId);
        $service->submitVersion($version->id, $submitterId);
        $this->assertValidation(fn () => $service->approveVersion($version->id, $makerId));
        $service->approveVersion($version->id, $checkerId);

        $this->assertSame(BudgetVersionStatusEnum::Locked, $version->fresh()->status);
        $actions = BudgetApproval::where('budget_version_id', $version->id)->pluck('action');
        $this->assertCount(3, $actions);
        $this->assertEqualsCanonicalizing(['Submitted', 'Approved', 'Locked'], $actions->all());
        $this->assertValidation(fn () => $service->addLine($version->id, null, $account->id, 2, 1, $checkerId));

        $approved = $budget->versions()->create(['version_number' => 2, 'status' => BudgetVersionStatusEnum::Approved]);
        $this->assertValidation(fn () => $service->addLine($approved->id, null, $account->id, 2, 1, $checkerId));
        $draft = $budget->versions()->create(['version_number' => 3, 'status' => BudgetVersionStatusEnum::Draft]);
        $this->assertValidation(fn () => $service->lockVersion($draft->id, $checkerId));
        $service->submitVersion($draft->id, $this->id());
        $this->assertValidation(fn () => $service->approveVersion($draft->id, $checkerId));
    }

    public function test_forecast_service_enforces_state_checker_and_rejection_revision_history(): void
    {
        $propertyId = $this->id();
        $makerId = $this->id();
        $checkerId = $this->id();
        $account = $this->account($propertyId, 'FOR-R1');
        $service = app(ForecastService::class);
        $forecast = $service->createForecast($propertyId, 2093, 'Lifecycle forecast', $makerId);
        $version = $forecast->versions()->firstOrFail();

        $service->addLine($version->id, null, $account->id, 1, 2250, $makerId);
        $this->assertValidation(fn () => $service->approveVersion($version->id, $checkerId));
        $this->assertValidation(fn () => $service->rejectVersion($version->id, $checkerId));

        $service->submitVersion($version->id, $makerId);
        $this->assertValidation(fn () => $service->addLine($version->id, null, $account->id, 2, 10, $makerId));
        $this->assertValidation(fn () => $service->approveVersion($version->id, $makerId));
        $this->assertValidation(fn () => $service->rejectVersion($version->id, $makerId));
        $service->rejectVersion($version->id, $checkerId, 'Revise forecast');

        $revision = $forecast->versions()->where('version_number', 2)->firstOrFail();
        $this->assertSame(ForecastVersionStatusEnum::Rejected, $version->fresh()->status);
        $this->assertSame(ForecastVersionStatusEnum::Draft, $revision->status);
        $this->assertSame($checkerId, $revision->created_by);
        $this->assertSame('2250.0000', (string) $version->lines()->value('amount'));
        $this->assertSame('2250.0000', (string) $revision->lines()->value('amount'));
        $this->assertValidation(fn () => $service->addLine($version->id, null, $account->id, 2, 10, $checkerId));

        $service->addLine($revision->id, null, $account->id, 2, 10, $checkerId);
        $this->assertSame(1, $version->lines()->count());
        $this->assertSame(2, $revision->lines()->count());
        $this->assertDatabaseHas('forecast_forecast_approvals', [
            'forecast_version_id' => $version->id,
            'action_by_id' => $checkerId,
            'action' => 'Rejected',
        ]);
    }

    public function test_forecast_approval_is_atomic_locked_and_one_per_property_year(): void
    {
        $propertyId = $this->id();
        $makerId = $this->id();
        $checkerId = $this->id();
        $submitterId = $this->id();
        $service = app(ForecastService::class);
        $account = $this->account($propertyId, 'FOR-R2');
        $forecast = $service->createForecast($propertyId, 2094, 'Approved forecast', $makerId);
        $version = $forecast->versions()->firstOrFail();
        $service->addLine($version->id, null, $account->id, 1, 1900, $makerId);
        $service->submitVersion($version->id, $submitterId);
        $this->assertValidation(fn () => $service->approveVersion($version->id, $makerId));
        $service->approveVersion($version->id, $checkerId);

        $this->assertSame(ForecastVersionStatusEnum::Locked, $version->fresh()->status);
        $actions = ForecastApproval::where('forecast_version_id', $version->id)->pluck('action');
        $this->assertCount(3, $actions);
        $this->assertEqualsCanonicalizing(['Submitted', 'Approved', 'Locked'], $actions->all());
        $this->assertValidation(fn () => $service->addLine($version->id, null, $account->id, 2, 1, $checkerId));

        $approved = $forecast->versions()->create(['version_number' => 2, 'status' => ForecastVersionStatusEnum::Approved]);
        $this->assertValidation(fn () => $service->addLine($approved->id, null, $account->id, 2, 1, $checkerId));
        $draft = $forecast->versions()->create(['version_number' => 3, 'status' => ForecastVersionStatusEnum::Draft]);
        $this->assertValidation(fn () => $service->lockVersion($draft->id, $checkerId));
        $service->submitVersion($draft->id, $this->id());
        $this->assertValidation(fn () => $service->approveVersion($draft->id, $checkerId));
    }

    private function assertValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected planning lifecycle validation failure.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function account(string $propertyId, string $code): Account
    {
        return Account::create([
            'property_id' => $propertyId,
            'code' => $code,
            'name' => $code,
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Expense,
            'account_category' => AccountCategoryEnum::Expense,
            'is_cash_equivalent' => false,
        ]);
    }

    private function id(): string
    {
        return (string) Str::ulid();
    }
}

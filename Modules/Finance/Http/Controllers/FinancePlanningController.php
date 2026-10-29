<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Budgeting\Services\BudgetService;
use Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum;
use Modules\Finance\Forecasting\Models\ForecastVersion;
use Modules\Finance\Forecasting\Services\ForecastService;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\Department\Models\Department;
use Shared\Services\CurrentPropertyService;

class FinancePlanningController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly ForecastService $forecasts,
    ) {}

    public function createBudget(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'budget.create');
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $this->budgets->createBudget($this->propertyId($request), (int) $data['fiscal_year'], $data['name'], $request->user()->id);

        return back()->with('success', 'Budget created.');
    }

    public function addBudgetLine(Request $request, BudgetVersion $version): RedirectResponse
    {
        $this->authorizePermission($request, 'budget.edit');
        $data = $this->lineData($request);
        $propertyId = $this->propertyId($request);
        $this->assertAccountProperty($data['account_id'], $propertyId);
        $this->assertDepartmentProperty($data['department_id'] ?? null, $propertyId);
        DB::transaction(function () use ($version, $propertyId, $data, $request): void {
            $locked = BudgetVersion::query()->with('budget')->lockForUpdate()->findOrFail($version->id);
            $this->assertBudgetVersionProperty($locked, $propertyId);
            abort_unless($locked->status === BudgetVersionStatusEnum::Draft, 409, 'Only draft budget versions may be edited.');
            $this->budgets->addLine($locked->id, $data['department_id'] ?? null, $data['account_id'], (int) $data['period_month'], (float) $data['amount'], $request->user()->id);
        });

        return back()->with('success', 'Budget line added.');
    }

    public function transitionBudget(Request $request, BudgetVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['submit', 'approve', 'reject'])],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        $action = $data['action'];
        $permission = $action === 'submit' ? 'budget.submit' : 'budget.approve';
        $this->authorizePermission($request, $permission);
        $propertyId = $this->propertyId($request);
        DB::transaction(function () use ($version, $propertyId, $action, $request, $data): void {
            $locked = BudgetVersion::query()->with('budget')->lockForUpdate()->findOrFail($version->id);
            $this->assertBudgetVersionProperty($locked, $propertyId);
            $this->lockPlanningScope('budget', $propertyId, (int) $locked->budget->fiscal_year);
            $expected = $action === 'submit' ? BudgetVersionStatusEnum::Draft : BudgetVersionStatusEnum::Submitted;
            abort_unless($locked->status === $expected, 409, 'Budget version is not eligible for this transition.');

            match ($action) {
                'submit' => $this->budgets->submitVersion($locked->id, $request->user()->id, $data['comments'] ?? null),
                'approve' => $this->budgets->approveVersion($locked->id, $request->user()->id, $data['comments'] ?? null),
                'reject' => $this->budgets->rejectVersion($locked->id, $request->user()->id, $data['comments'] ?? null),
            };
        });

        return back()->with('success', 'Budget version updated.');
    }

    public function createForecast(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'forecast.create');
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $this->forecasts->createForecast($this->propertyId($request), (int) $data['fiscal_year'], $data['name'], $request->user()->id);

        return back()->with('success', 'Forecast created.');
    }

    public function addForecastLine(Request $request, ForecastVersion $version): RedirectResponse
    {
        $this->authorizePermission($request, 'forecast.edit');
        $data = $this->lineData($request);
        $propertyId = $this->propertyId($request);
        $this->assertAccountProperty($data['account_id'], $propertyId);
        $this->assertDepartmentProperty($data['department_id'] ?? null, $propertyId);
        DB::transaction(function () use ($version, $propertyId, $data, $request): void {
            $locked = ForecastVersion::query()->with('forecast')->lockForUpdate()->findOrFail($version->id);
            $this->assertForecastVersionProperty($locked, $propertyId);
            abort_unless($locked->status === ForecastVersionStatusEnum::Draft, 409, 'Only draft forecast versions may be edited.');
            $this->forecasts->addLine($locked->id, $data['department_id'] ?? null, $data['account_id'], (int) $data['period_month'], (float) $data['amount'], $request->user()->id);
        });

        return back()->with('success', 'Forecast line saved.');
    }

    public function transitionForecast(Request $request, ForecastVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['submit', 'approve', 'reject'])],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        $action = $data['action'];
        $permission = $action === 'submit' ? 'forecast.submit' : 'forecast.approve';
        $this->authorizePermission($request, $permission);
        $propertyId = $this->propertyId($request);
        DB::transaction(function () use ($version, $propertyId, $action, $request, $data): void {
            $locked = ForecastVersion::query()->with('forecast')->lockForUpdate()->findOrFail($version->id);
            $this->assertForecastVersionProperty($locked, $propertyId);
            $this->lockPlanningScope('forecast', $propertyId, (int) $locked->forecast->fiscal_year);
            $expected = $action === 'submit' ? ForecastVersionStatusEnum::Draft : ForecastVersionStatusEnum::Submitted;
            abort_unless($locked->status === $expected, 409, 'Forecast version is not eligible for this transition.');

            match ($action) {
                'submit' => $this->forecasts->submitVersion($locked->id, $request->user()->id, $data['comments'] ?? null),
                'approve' => $this->forecasts->approveVersion($locked->id, $request->user()->id, $data['comments'] ?? null),
                'reject' => $this->forecasts->rejectVersion($locked->id, $request->user()->id, $data['comments'] ?? null),
            };
        });

        return back()->with('success', 'Forecast version updated.');
    }

    private function lineData(Request $request): array
    {
        return $request->validate([
            'department_id' => ['nullable', 'ulid'],
            'account_id' => ['required', 'ulid'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);
    }

    private function assertBudgetVersionProperty(BudgetVersion $version, string $propertyId): void
    {
        $version->loadMissing('budget');
        abort_unless($version->budget && $version->budget->property_id === $propertyId, 404);
    }

    private function assertForecastVersionProperty(ForecastVersion $version, string $propertyId): void
    {
        $version->loadMissing('forecast');
        abort_unless($version->forecast && $version->forecast->property_id === $propertyId, 404);
    }

    private function assertAccountProperty(string $accountId, string $propertyId): void
    {
        abort_unless(Account::query()
            ->whereKey($accountId)
            ->where('property_id', $propertyId)
            ->whereIn('account_type', [AccountTypeEnum::Revenue->value, AccountTypeEnum::CostOfSales->value, AccountTypeEnum::Expense->value])
            ->exists(), 404);
    }

    private function assertDepartmentProperty(?string $departmentId, string $propertyId): void
    {
        if ($departmentId === null) {
            return;
        }

        abort_unless(Department::query()
            ->whereKey($departmentId)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->exists(), 404);
    }

    private function lockPlanningScope(string $kind, string $propertyId, int $year): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance:{$kind}:{$propertyId}:{$year}"]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'Unauthorized.');
    }

    private function propertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}

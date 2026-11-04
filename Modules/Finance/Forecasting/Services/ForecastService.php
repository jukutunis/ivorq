<?php

namespace Modules\Finance\Forecasting\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum;
use Modules\Finance\Forecasting\Models\Forecast;
use Modules\Finance\Forecasting\Models\ForecastApproval;
use Modules\Finance\Forecasting\Models\ForecastLine;
use Modules\Finance\Forecasting\Models\ForecastVersion;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;

class ForecastService
{
    public function createForecast(string $propertyId, int $fiscalYear, string $name, ?string $userId = null): Forecast
    {
        return DB::transaction(function () use ($propertyId, $fiscalYear, $name, $userId): Forecast {
            $forecast = new Forecast;
            $forecast->forceFill([
                'property_id' => $propertyId,
                'fiscal_year' => $fiscalYear,
                'name' => $name,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            $version = new ForecastVersion;
            $version->forceFill([
                'forecast_id' => $forecast->id,
                'version_number' => 1,
                'status' => ForecastVersionStatusEnum::Draft,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            $this->autoSeedVersion($version);
            Log::info('ForecastCreated', ['forecast_id' => $forecast->id]);

            return $forecast;
        });
    }

    protected function autoSeedVersion(ForecastVersion $version): void
    {
        $propertyId = $version->forecast->property_id;
        $year = $version->forecast->fiscal_year;

        $cacheKey = "budget:active:{$propertyId}:{$year}";
        $activeBudgetVersionId = Cache::get($cacheKey);
        if (! $activeBudgetVersionId) {
            $budgetVersion = BudgetVersion::query()
                ->whereHas('budget', function ($query) use ($propertyId, $year): void {
                    $query->where('property_id', $propertyId)->where('fiscal_year', $year);
                })
                ->where('status', BudgetVersionStatusEnum::Locked)
                ->first();
            $activeBudgetVersionId = $budgetVersion?->id;
        }

        $budgetLines = $activeBudgetVersionId
            ? BudgetLine::query()->where('budget_version_id', $activeBudgetVersionId)->get()
            : collect();
        $actuals = LedgerBalance::query()
            ->where('property_id', $propertyId)
            ->where('period_year', $year)
            ->get();
        $seeded = [];
        $accounts = Account::query()->where('property_id', $propertyId)->get()->keyBy('id');

        foreach ($actuals as $actual) {
            $account = $accounts->get($actual->account_id);
            if (! $account || ! in_array($account->account_type, [AccountTypeEnum::Revenue, AccountTypeEnum::CostOfSales, AccountTypeEnum::Expense], true)) {
                continue;
            }

            $actualNet = $account->normal_balance === NormalBalanceEnum::Debit
                ? ($actual->debit_total - $actual->credit_total)
                : ($actual->credit_total - $actual->debit_total);
            $key = "null-{$actual->account_id}-{$actual->period_month}";
            if (! isset($seeded[$key])) {
                $seeded[$key] = true;
                ForecastLine::create([
                    'forecast_version_id' => $version->id,
                    'department_id' => null,
                    'account_id' => $actual->account_id,
                    'period_month' => $actual->period_month,
                    'amount' => $actualNet,
                ]);
            }
        }

        foreach ($budgetLines as $line) {
            $hasActualForMonth = $actuals
                ->where('account_id', $line->account_id)
                ->where('period_month', $line->period_month)
                ->isNotEmpty();

            if (! $hasActualForMonth) {
                ForecastLine::create([
                    'forecast_version_id' => $version->id,
                    'department_id' => $line->department_id,
                    'account_id' => $line->account_id,
                    'period_month' => $line->period_month,
                    'amount' => $line->amount,
                ]);
            }
        }
    }

    public function addLine(string $versionId, ?string $departmentId, string $accountId, int $month, float $amount, ?string $userId = null): ForecastLine
    {
        return DB::transaction(function () use ($versionId, $departmentId, $accountId, $month, $amount, $userId): ForecastLine {
            $version = ForecastVersion::query()->with('forecast')->lockForUpdate()->findOrFail($versionId);
            $this->requireStatus($version, ForecastVersionStatusEnum::Draft, 'Only draft forecast versions may be edited.');

            $account = Account::findOrFail($accountId);
            $allowedTypes = [
                AccountTypeEnum::Revenue,
                AccountTypeEnum::CostOfSales,
                AccountTypeEnum::Expense,
            ];

            if ($account->property_id !== $version->forecast->property_id || ! in_array($account->account_type, $allowedTypes, true)) {
                throw ValidationException::withMessages(['account_id' => 'Only active-Property P&L accounts are allowed in the forecast.']);
            }

            $line = ForecastLine::query()
                ->where('forecast_version_id', $versionId)
                ->where('department_id', $departmentId)
                ->where('account_id', $accountId)
                ->where('period_month', $month)
                ->first();

            if ($line) {
                $line->forceFill(['amount' => $amount, 'updated_by' => $userId])->save();

                return $line;
            }

            $line = new ForecastLine;
            $line->forceFill([
                'forecast_version_id' => $versionId,
                'department_id' => $departmentId,
                'account_id' => $accountId,
                'period_month' => $month,
                'amount' => $amount,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            return $line;
        });
    }

    public function submitVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = ForecastVersion::query()->lockForUpdate()->findOrFail($versionId);
            $this->requireStatus($version, ForecastVersionStatusEnum::Draft, 'Only draft forecast versions may be submitted.');
            $this->recordTransition($version, $userId, ForecastVersionStatusEnum::Submitted, 'Submitted', $comments);
        });
        Log::info('ForecastSubmitted', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function approveVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = ForecastVersion::query()->with('forecast')->lockForUpdate()->findOrFail($versionId);
            $this->lockPlanningScope($version->forecast);
            $this->requireStatus($version, ForecastVersionStatusEnum::Submitted, 'Only submitted forecast versions may be approved.');
            $this->assertIndependentChecker($version, $userId);

            $existingApproved = ForecastVersion::query()
                ->whereHas('forecast', function ($query) use ($version): void {
                    $query->where('property_id', $version->forecast->property_id)
                        ->where('fiscal_year', $version->forecast->fiscal_year);
                })
                ->whereIn('status', [ForecastVersionStatusEnum::Approved->value, ForecastVersionStatusEnum::Locked->value])
                ->exists();

            if ($existingApproved) {
                throw ValidationException::withMessages(['status' => 'Only one approved forecast version is allowed per property and year.']);
            }

            $this->recordTransition($version, $userId, ForecastVersionStatusEnum::Approved, 'Approved', $comments);
            $this->recordTransition($version, $userId, ForecastVersionStatusEnum::Locked, 'Locked', 'Auto-locked upon approval.');
            Cache::put("forecast:active:{$version->forecast->property_id}:{$version->forecast->fiscal_year}", $version->id);
        });
        Log::info('ForecastApproved', ['version_id' => $versionId, 'user_id' => $userId]);
        Log::info('ForecastLocked', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function lockVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = ForecastVersion::query()->with('forecast')->lockForUpdate()->findOrFail($versionId);
            $this->lockPlanningScope($version->forecast);
            $this->requireStatus($version, ForecastVersionStatusEnum::Approved, 'Only approved forecast versions may be locked.');
            $this->recordTransition($version, $userId, ForecastVersionStatusEnum::Locked, 'Locked', $comments);
            Cache::put("forecast:active:{$version->forecast->property_id}:{$version->forecast->fiscal_year}", $version->id);
        });
        Log::info('ForecastLocked', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function rejectVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = ForecastVersion::query()->with(['forecast', 'lines'])->lockForUpdate()->findOrFail($versionId);
            $this->lockPlanningScope($version->forecast);
            $forecast = Forecast::query()->lockForUpdate()->findOrFail($version->forecast_id);
            $this->requireStatus($version, ForecastVersionStatusEnum::Submitted, 'Only submitted forecast versions may be rejected.');
            $this->assertIndependentChecker($version, $userId);
            $this->recordTransition($version, $userId, ForecastVersionStatusEnum::Rejected, 'Rejected', $comments);

            $nextNumber = ((int) $forecast->versions()->withTrashed()->max('version_number')) + 1;
            $revision = new ForecastVersion;
            $revision->forceFill([
                'forecast_id' => $forecast->id,
                'version_number' => $nextNumber,
                'status' => ForecastVersionStatusEnum::Draft,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            foreach ($version->lines as $sourceLine) {
                $line = new ForecastLine;
                $line->forceFill([
                    'forecast_version_id' => $revision->id,
                    'department_id' => $sourceLine->department_id,
                    'account_id' => $sourceLine->account_id,
                    'period_month' => $sourceLine->period_month,
                    'amount' => $sourceLine->amount,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ])->save();
            }
        });
        Log::info('ForecastRejected', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    private function recordTransition(ForecastVersion $version, string $userId, ForecastVersionStatusEnum $status, string $action, ?string $comments): void
    {
        $version->forceFill(['status' => $status, 'updated_by' => $userId])->save();
        ForecastApproval::create([
            'forecast_version_id' => $version->id,
            'action_by_id' => $userId,
            'action' => $action,
            'comments' => $comments,
            'action_at' => now(),
        ]);
    }

    private function requireStatus(ForecastVersion $version, ForecastVersionStatusEnum $expected, string $message): void
    {
        if ($version->status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function assertIndependentChecker(ForecastVersion $version, string $checkerId): void
    {
        $submittedBy = ForecastApproval::query()
            ->where('forecast_version_id', $version->id)
            ->where('action', 'Submitted')
            ->orderByDesc('action_at')
            ->value('action_by_id');

        if ($submittedBy === null) {
            throw ValidationException::withMessages(['checker' => 'Forecast submission evidence is required before checker action.']);
        }

        if ($submittedBy === $checkerId || ($version->created_by !== null && $version->created_by === $checkerId)) {
            throw ValidationException::withMessages(['checker' => 'Forecast checker must differ from the maker and submitting actor.']);
        }
    }

    private function lockPlanningScope(Forecast $forecast): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance:forecast:{$forecast->property_id}:{$forecast->fiscal_year}"]);
        }
    }
}

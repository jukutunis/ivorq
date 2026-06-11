<?php

namespace Modules\Finance\Forecasting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum;
use Modules\Finance\Forecasting\Models\Forecast;
use Modules\Finance\Forecasting\Models\ForecastVersion;
use Modules\Finance\Forecasting\Models\ForecastLine;
use Modules\Finance\Forecasting\Models\ForecastApproval;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;

class ForecastService
{
    public function createForecast(string $propertyId, int $fiscalYear, string $name, ?string $userId = null): Forecast
    {
        return DB::transaction(function () use ($propertyId, $fiscalYear, $name, $userId) {
            $forecast = Forecast::create([
                'property_id' => $propertyId,
                'fiscal_year' => $fiscalYear,
                'name' => $name,
                'created_by' => $userId,
            ]);

            $version = $forecast->versions()->create([
                'version_number' => 1,
                'status' => ForecastVersionStatusEnum::Draft,
                'created_by' => $userId,
            ]);

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
        if (!$activeBudgetVersionId) {
            $budgetVersion = BudgetVersion::whereHas('budget', function ($q) use ($propertyId, $year) {
                $q->where('property_id', $propertyId)->where('fiscal_year', $year);
            })->where('status', \Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum::Locked)->first();
            $activeBudgetVersionId = $budgetVersion ? $budgetVersion->id : null;
        }

        $budgetLines = collect();
        if ($activeBudgetVersionId) {
            $budgetLines = BudgetLine::where('budget_version_id', $activeBudgetVersionId)->get();
        }

        $actuals = LedgerBalance::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->get();
        
        $seeded = [];
        $accounts = Account::where('property_id', $propertyId)->get()->keyBy('id');

        foreach ($actuals as $actual) {
            $account = $accounts->get($actual->account_id);
            if (!$account || !in_array($account->account_type, [AccountTypeEnum::Revenue, AccountTypeEnum::CostOfSales, AccountTypeEnum::Expense])) {
                continue;
            }

            $actualNet = ($account->normal_balance === \Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum::Debit)
                ? ($actual->debit_total - $actual->credit_total)
                : ($actual->credit_total - $actual->debit_total);

            $key = "null-{$actual->account_id}-{$actual->period_month}";
            if (!isset($seeded[$key])) {
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
            $hasActualForMonth = $actuals->where('account_id', $line->account_id)->where('period_month', $line->period_month)->isNotEmpty();

            if (!$hasActualForMonth) {
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
        $version = ForecastVersion::findOrFail($versionId);

        if ($version->status === ForecastVersionStatusEnum::Locked) {
            throw ValidationException::withMessages(['status' => 'Locked forecast versions are immutable.']);
        }

        $account = Account::findOrFail($accountId);

        $allowedTypes = [
            AccountTypeEnum::Revenue,
            AccountTypeEnum::CostOfSales,
            AccountTypeEnum::Expense,
        ];

        if (!in_array($account->account_type, $allowedTypes)) {
            throw ValidationException::withMessages(['account_id' => 'Only P&L accounts are allowed in the forecast.']);
        }

        $line = ForecastLine::where('forecast_version_id', $versionId)
            ->where('department_id', $departmentId)
            ->where('account_id', $accountId)
            ->where('period_month', $month)
            ->first();

        if ($line) {
            $line->update(['amount' => $amount, 'updated_by' => $userId]);
            return $line;
        }

        return ForecastLine::create([
            'forecast_version_id' => $versionId,
            'department_id' => $departmentId,
            'account_id' => $accountId,
            'period_month' => $month,
            'amount' => $amount,
            'created_by' => $userId,
        ]);
    }

    public function submitVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $this->transitionVersion($versionId, $userId, ForecastVersionStatusEnum::Submitted, 'Submitted', $comments);
        Log::info('ForecastSubmitted', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function approveVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $version = ForecastVersion::with('forecast')->findOrFail($versionId);

        $existingApproved = ForecastVersion::whereHas('forecast', function ($query) use ($version) {
            $query->where('property_id', $version->forecast->property_id)
                  ->where('fiscal_year', $version->forecast->fiscal_year);
        })->whereIn('status', [ForecastVersionStatusEnum::Approved->value, ForecastVersionStatusEnum::Locked->value])
          ->exists();

        if ($existingApproved) {
            throw ValidationException::withMessages(['status' => 'Only one approved forecast version is allowed per property and year.']);
        }

        $this->transitionVersion($versionId, $userId, ForecastVersionStatusEnum::Approved, 'Approved', $comments);
        Log::info('ForecastApproved', ['version_id' => $versionId, 'user_id' => $userId]);

        $this->lockVersion($versionId, $userId, 'Auto-locked upon approval.');
    }

    public function lockVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $this->transitionVersion($versionId, $userId, ForecastVersionStatusEnum::Locked, 'Locked', $comments);
        Log::info('ForecastLocked', ['version_id' => $versionId, 'user_id' => $userId]);

        $version = ForecastVersion::with('forecast')->findOrFail($versionId);
        $cacheKey = "forecast:active:{$version->forecast->property_id}:{$version->forecast->fiscal_year}";
        Cache::put($cacheKey, $version->id);
    }

    public function rejectVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $this->transitionVersion($versionId, $userId, ForecastVersionStatusEnum::Rejected, 'Rejected', $comments);
        Log::info('ForecastRejected', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    protected function transitionVersion(string $versionId, string $userId, ForecastVersionStatusEnum $status, string $action, ?string $comments): void
    {
        DB::transaction(function () use ($versionId, $userId, $status, $action, $comments) {
            $version = ForecastVersion::findOrFail($versionId);
            $version->update(['status' => $status]);

            ForecastApproval::create([
                'forecast_version_id' => $versionId,
                'action_by_id' => $userId,
                'action' => $action,
                'comments' => $comments,
                'action_at' => now(),
            ]);
        });
    }
}

<?php

namespace Modules\Finance\Forecasting\Services;

use Modules\Finance\Forecasting\Models\ForecastVersion;
use Modules\Finance\Forecasting\Models\ForecastLine;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Illuminate\Support\Facades\Cache;

class ForecastVarianceService
{
    public function getVariance(string $propertyId, int $year): array
    {
        $fCacheKey = "forecast:active:{$propertyId}:{$year}";
        $activeForecastVersionId = Cache::get($fCacheKey);

        if (!$activeForecastVersionId) {
            $fVersion = ForecastVersion::whereHas('forecast', function ($q) use ($propertyId, $year) {
                $q->where('property_id', $propertyId)->where('fiscal_year', $year);
            })->where('status', \Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum::Locked)->first();

            if ($fVersion) {
                $activeForecastVersionId = $fVersion->id;
                Cache::put($fCacheKey, $activeForecastVersionId);
            }
        }

        $bCacheKey = "budget:active:{$propertyId}:{$year}";
        $activeBudgetVersionId = Cache::get($bCacheKey);

        if (!$activeBudgetVersionId) {
            $bVersion = BudgetVersion::whereHas('budget', function ($q) use ($propertyId, $year) {
                $q->where('property_id', $propertyId)->where('fiscal_year', $year);
            })->where('status', \Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum::Locked)->first();

            if ($bVersion) {
                $activeBudgetVersionId = $bVersion->id;
                Cache::put($bCacheKey, $activeBudgetVersionId);
            }
        }

        $forecastLines = collect();
        if ($activeForecastVersionId) {
            $forecastLines = ForecastLine::with('account')
                ->where('forecast_version_id', $activeForecastVersionId)
                ->get();
        }

        $budgetLines = collect();
        if ($activeBudgetVersionId) {
            $budgetLines = BudgetLine::where('budget_version_id', $activeBudgetVersionId)->get();
        }

        $actuals = LedgerBalance::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->get();

        $accountIds = collect()
            ->merge($forecastLines->pluck('account_id'))
            ->merge($budgetLines->pluck('account_id'))
            ->merge($actuals->pluck('account_id'))
            ->unique();

        $results = [];
        
        $accounts = \Modules\Finance\GeneralLedger\Models\Account::whereIn('id', $accountIds)->get()->keyBy('id');

        foreach ($accountIds as $accId) {
            $account = $accounts->get($accId);
            if (!$account || !in_array($account->account_type->value, ['Revenue', 'CostOfSales', 'Expense'])) continue;

            $budgetTotal = $budgetLines->where('account_id', $accId)->sum('amount');
            $forecastTotal = $forecastLines->where('account_id', $accId)->sum('amount');
            
            $actualSum = 0.0;
            $accActuals = $actuals->where('account_id', $accId);
            foreach ($accActuals as $act) {
                if ($account->normal_balance === \Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum::Debit) {
                    $actualSum += ($act->debit_total - $act->credit_total);
                } else {
                    $actualSum += ($act->credit_total - $act->debit_total);
                }
            }

            $fVsB = $forecastTotal - $budgetTotal;
            $fVsA = $forecastTotal - $actualSum;
            $varPercent = $budgetTotal > 0 ? ($fVsB / $budgetTotal) * 100 : 0;

            $results[] = [
                'account_id' => $accId,
                'budget' => $budgetTotal,
                'actual' => $actualSum,
                'forecast' => $forecastTotal,
                'forecast_vs_budget' => $fVsB,
                'forecast_vs_actual' => $fVsA,
                'variance_percent' => $varPercent,
                'year_end_projection' => $forecastTotal,
            ];
        }

        return $results;
    }
}

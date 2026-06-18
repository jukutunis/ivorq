<?php

namespace Modules\Finance\Budgeting\Services;

use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Illuminate\Support\Facades\Cache;

class BudgetVarianceService
{
    public function getVariance(string $propertyId, int $year, int $month): array
    {
        $cacheKey = "budget:active:{$propertyId}:{$year}";
        $activeVersionId = Cache::get($cacheKey);

        if (!$activeVersionId) {
            $version = BudgetVersion::whereHas('budget', function ($q) use ($propertyId, $year) {
                $q->where('property_id', $propertyId)->where('fiscal_year', $year);
            })->where('status', \Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum::Locked)->first();

            if (!$version) {
                return [];
            }
            $activeVersionId = $version->id;
            Cache::put($cacheKey, $activeVersionId);
        }

        $budgetLines = \Modules\Finance\Budgeting\Models\BudgetLine::with('account')
            ->where('budget_version_id', $activeVersionId)
            ->where('period_month', $month)
            ->get();

        $accountIds = $budgetLines->pluck('account_id')->unique()->toArray();

        $actuals = LedgerBalance::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('account_id', $accountIds)
            ->get()
            ->keyBy('account_id');

        $variance = [];
        foreach ($budgetLines as $line) {
            $actual = $actuals->get($line->account_id);
            $account = $line->account;
            
            $actualNet = 0.0;
            if ($account) {
                if ($account->normal_balance === \Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum::Debit) {
                    $actualNet = $actual ? ($actual->debit_total - $actual->credit_total) : 0;
                } else {
                    $actualNet = $actual ? ($actual->credit_total - $actual->debit_total) : 0;
                }
            }

            $variance[] = [
                'department_id' => $line->department_id,
                'account_id' => $line->account_id,
                'budget_amount' => $line->amount,
                'actual_amount' => $actualNet,
                'variance_amount' => $line->amount - $actualNet,
            ];
        }

        return $variance;
    }

    public function validateDepartmentBudget(string $propertyId, string $departmentId, int $year, int $month, float $requestedAmount): void
    {
        $variances = $this->getVariance($propertyId, $year, $month);

        // If no active budget, we assume no budget is configured and therefore requests cannot proceed (strict budget enforcement).
        if (empty($variances)) {
            throw new \Shared\Exceptions\BusinessLogicException('No active budget found for the period.');
        }

        $availableBudget = collect($variances)
            ->where('department_id', $departmentId)
            ->sum('variance_amount');

        if ($requestedAmount > $availableBudget) {
            throw new \Shared\Exceptions\BusinessLogicException("Purchase Request amount ({$requestedAmount}) exceeds available department budget ({$availableBudget}).");
        }
    }
}

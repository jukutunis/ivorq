<?php

namespace Modules\Finance\Budgeting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\Budget;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\Budgeting\Models\BudgetApproval;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;

class BudgetService
{
    public function createBudget(string $propertyId, int $fiscalYear, string $name, ?string $userId = null): Budget
    {
        return DB::transaction(function () use ($propertyId, $fiscalYear, $name, $userId) {
            $budget = Budget::create([
                'property_id' => $propertyId,
                'fiscal_year' => $fiscalYear,
                'name' => $name,
                'created_by' => $userId,
            ]);

            $budget->versions()->create([
                'version_number' => 1,
                'status' => BudgetVersionStatusEnum::Draft,
                'created_by' => $userId,
            ]);

            Log::info('BudgetCreated', ['budget_id' => $budget->id]);

            return $budget;
        });
    }

    public function addLine(string $versionId, ?string $departmentId, string $accountId, int $month, float $amount, ?string $userId = null): BudgetLine
    {
        $version = BudgetVersion::findOrFail($versionId);

        if ($version->status === BudgetVersionStatusEnum::Locked) {
            throw ValidationException::withMessages(['status' => 'Locked budget versions are immutable.']);
        }

        $account = Account::findOrFail($accountId);

        $allowedTypes = [
            AccountTypeEnum::Revenue,
            AccountTypeEnum::CostOfSales,
            AccountTypeEnum::Expense,
        ];

        if (!in_array($account->account_type, $allowedTypes)) {
            throw ValidationException::withMessages(['account_id' => 'Only P&L accounts are allowed in the operating budget.']);
        }

        $exists = BudgetLine::where('budget_version_id', $versionId)
            ->where('department_id', $departmentId)
            ->where('account_id', $accountId)
            ->where('period_month', $month)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['line' => 'Budget line already exists for this department, account, and month.']);
        }

        return BudgetLine::create([
            'budget_version_id' => $versionId,
            'department_id' => $departmentId,
            'account_id' => $accountId,
            'period_month' => $month,
            'amount' => $amount,
            'created_by' => $userId,
        ]);
    }

    public function submitVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $this->transitionVersion($versionId, $userId, BudgetVersionStatusEnum::Submitted, 'Submitted', $comments);
        Log::info('BudgetSubmitted', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function approveVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $version = BudgetVersion::with('budget')->findOrFail($versionId);

        $existingApproved = BudgetVersion::whereHas('budget', function ($query) use ($version) {
            $query->where('property_id', $version->budget->property_id)
                  ->where('fiscal_year', $version->budget->fiscal_year);
        })->whereIn('status', [BudgetVersionStatusEnum::Approved->value, BudgetVersionStatusEnum::Locked->value])
          ->exists();

        if ($existingApproved) {
            throw ValidationException::withMessages(['status' => 'Only one approved version is allowed per property and year.']);
        }

        $this->transitionVersion($versionId, $userId, BudgetVersionStatusEnum::Approved, 'Approved', $comments);
        Log::info('BudgetApproved', ['version_id' => $versionId, 'user_id' => $userId]);

        $this->lockVersion($versionId, $userId, 'Auto-locked upon approval.');
    }

    public function lockVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $this->transitionVersion($versionId, $userId, BudgetVersionStatusEnum::Locked, 'Locked', $comments);
        Log::info('BudgetLocked', ['version_id' => $versionId, 'user_id' => $userId]);

        $version = BudgetVersion::with('budget')->findOrFail($versionId);
        $cacheKey = "budget:active:{$version->budget->property_id}:{$version->budget->fiscal_year}";
        Cache::put($cacheKey, $version->id);
    }

    public function rejectVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        $this->transitionVersion($versionId, $userId, BudgetVersionStatusEnum::Rejected, 'Rejected', $comments);
        Log::info('BudgetRejected', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    protected function transitionVersion(string $versionId, string $userId, BudgetVersionStatusEnum $status, string $action, ?string $comments): void
    {
        DB::transaction(function () use ($versionId, $userId, $status, $action, $comments) {
            $version = BudgetVersion::findOrFail($versionId);
            $version->update(['status' => $status]);

            BudgetApproval::create([
                'budget_version_id' => $versionId,
                'action_by_id' => $userId,
                'action' => $action,
                'comments' => $comments,
                'action_at' => now(),
            ]);
        });
    }
}

<?php

namespace Modules\Finance\Budgeting\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\Budget;
use Modules\Finance\Budgeting\Models\BudgetApproval;
use Modules\Finance\Budgeting\Models\BudgetLine;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Models\Account;

class BudgetService
{
    public function createBudget(string $propertyId, int $fiscalYear, string $name, ?string $userId = null): Budget
    {
        return DB::transaction(function () use ($propertyId, $fiscalYear, $name, $userId): Budget {
            $budget = new Budget;
            $budget->forceFill([
                'property_id' => $propertyId,
                'fiscal_year' => $fiscalYear,
                'name' => $name,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            $version = new BudgetVersion;
            $version->forceFill([
                'budget_id' => $budget->id,
                'version_number' => 1,
                'status' => BudgetVersionStatusEnum::Draft,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            Log::info('BudgetCreated', ['budget_id' => $budget->id]);

            return $budget;
        });
    }

    public function addLine(string $versionId, ?string $departmentId, string $accountId, int $month, float $amount, ?string $userId = null): BudgetLine
    {
        return DB::transaction(function () use ($versionId, $departmentId, $accountId, $month, $amount, $userId): BudgetLine {
            $version = BudgetVersion::query()->with('budget')->lockForUpdate()->findOrFail($versionId);
            $this->requireStatus($version, BudgetVersionStatusEnum::Draft, 'Only draft budget versions may be edited.');

            $account = Account::findOrFail($accountId);
            $allowedTypes = [
                AccountTypeEnum::Revenue,
                AccountTypeEnum::CostOfSales,
                AccountTypeEnum::Expense,
            ];

            if ($account->property_id !== $version->budget->property_id || ! in_array($account->account_type, $allowedTypes, true)) {
                throw ValidationException::withMessages(['account_id' => 'Only active-Property P&L accounts are allowed in the operating budget.']);
            }

            $exists = BudgetLine::query()
                ->where('budget_version_id', $versionId)
                ->where('department_id', $departmentId)
                ->where('account_id', $accountId)
                ->where('period_month', $month)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['line' => 'Budget line already exists for this department, account, and month.']);
            }

            $line = new BudgetLine;
            $line->forceFill([
                'budget_version_id' => $versionId,
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
            $version = BudgetVersion::query()->lockForUpdate()->findOrFail($versionId);
            $this->requireStatus($version, BudgetVersionStatusEnum::Draft, 'Only draft budget versions may be submitted.');
            $this->recordTransition($version, $userId, BudgetVersionStatusEnum::Submitted, 'Submitted', $comments);
        });
        Log::info('BudgetSubmitted', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function approveVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = BudgetVersion::query()->with('budget')->lockForUpdate()->findOrFail($versionId);
            $this->lockPlanningScope($version->budget);
            $this->requireStatus($version, BudgetVersionStatusEnum::Submitted, 'Only submitted budget versions may be approved.');
            $this->assertIndependentChecker($version, $userId);

            $existingApproved = BudgetVersion::query()
                ->whereHas('budget', function ($query) use ($version): void {
                    $query->where('property_id', $version->budget->property_id)
                        ->where('fiscal_year', $version->budget->fiscal_year);
                })
                ->whereIn('status', [BudgetVersionStatusEnum::Approved->value, BudgetVersionStatusEnum::Locked->value])
                ->exists();

            if ($existingApproved) {
                throw ValidationException::withMessages(['status' => 'Only one approved version is allowed per property and year.']);
            }

            $this->recordTransition($version, $userId, BudgetVersionStatusEnum::Approved, 'Approved', $comments);
            $this->recordTransition($version, $userId, BudgetVersionStatusEnum::Locked, 'Locked', 'Auto-locked upon approval.');
            Cache::put("budget:active:{$version->budget->property_id}:{$version->budget->fiscal_year}", $version->id);
        });
        Log::info('BudgetApproved', ['version_id' => $versionId, 'user_id' => $userId]);
        Log::info('BudgetLocked', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function lockVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = BudgetVersion::query()->with('budget')->lockForUpdate()->findOrFail($versionId);
            $this->lockPlanningScope($version->budget);
            $this->requireStatus($version, BudgetVersionStatusEnum::Approved, 'Only approved budget versions may be locked.');
            $this->recordTransition($version, $userId, BudgetVersionStatusEnum::Locked, 'Locked', $comments);
            Cache::put("budget:active:{$version->budget->property_id}:{$version->budget->fiscal_year}", $version->id);
        });
        Log::info('BudgetLocked', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    public function rejectVersion(string $versionId, string $userId, ?string $comments = null): void
    {
        DB::transaction(function () use ($versionId, $userId, $comments): void {
            $version = BudgetVersion::query()->with(['budget', 'lines'])->lockForUpdate()->findOrFail($versionId);
            $this->lockPlanningScope($version->budget);
            $budget = Budget::query()->lockForUpdate()->findOrFail($version->budget_id);
            $this->requireStatus($version, BudgetVersionStatusEnum::Submitted, 'Only submitted budget versions may be rejected.');
            $this->assertIndependentChecker($version, $userId);
            $this->recordTransition($version, $userId, BudgetVersionStatusEnum::Rejected, 'Rejected', $comments);

            $nextNumber = ((int) $budget->versions()->withTrashed()->max('version_number')) + 1;
            $revision = new BudgetVersion;
            $revision->forceFill([
                'budget_id' => $budget->id,
                'version_number' => $nextNumber,
                'status' => BudgetVersionStatusEnum::Draft,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            foreach ($version->lines as $sourceLine) {
                $line = new BudgetLine;
                $line->forceFill([
                    'budget_version_id' => $revision->id,
                    'department_id' => $sourceLine->department_id,
                    'account_id' => $sourceLine->account_id,
                    'period_month' => $sourceLine->period_month,
                    'amount' => $sourceLine->amount,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ])->save();
            }
        });
        Log::info('BudgetRejected', ['version_id' => $versionId, 'user_id' => $userId]);
    }

    private function recordTransition(BudgetVersion $version, string $userId, BudgetVersionStatusEnum $status, string $action, ?string $comments): void
    {
        $version->forceFill(['status' => $status, 'updated_by' => $userId])->save();
        BudgetApproval::create([
            'budget_version_id' => $version->id,
            'action_by_id' => $userId,
            'action' => $action,
            'comments' => $comments,
            'action_at' => now(),
        ]);
    }

    private function requireStatus(BudgetVersion $version, BudgetVersionStatusEnum $expected, string $message): void
    {
        if ($version->status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function assertIndependentChecker(BudgetVersion $version, string $checkerId): void
    {
        $submittedBy = BudgetApproval::query()
            ->where('budget_version_id', $version->id)
            ->where('action', 'Submitted')
            ->orderByDesc('action_at')
            ->value('action_by_id');

        if ($submittedBy === null) {
            throw ValidationException::withMessages(['checker' => 'Budget submission evidence is required before checker action.']);
        }

        if ($submittedBy === $checkerId || ($version->created_by !== null && $version->created_by === $checkerId)) {
            throw ValidationException::withMessages(['checker' => 'Budget checker must differ from the maker and submitting actor.']);
        }
    }

    private function lockPlanningScope(Budget $budget): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance:budget:{$budget->property_id}:{$budget->fiscal_year}"]);
        }
    }
}

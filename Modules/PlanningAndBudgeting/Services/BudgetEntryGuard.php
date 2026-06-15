<?php

namespace Modules\PlanningAndBudgeting\Services;

use Exception;
use Modules\PlanningAndBudgeting\Models\BudgetEntry;
use Modules\PlanningAndBudgeting\Models\BudgetCategory;
use Modules\PlanningAndBudgeting\Enums\BudgetCategoryTypeEnum;

class BudgetEntryGuard
{
    /**
     * Enforce Cost Center vs Revenue Center Rules
     */
    public function validateCategoryAssignment(string $centerType, BudgetCategory $category): void
    {
        if ($centerType === 'COST_CENTER' && $category->category_type === BudgetCategoryTypeEnum::Revenue) {
            throw new Exception("GovernanceException: Cost Centers cannot receive REVENUE categories.");
        }
    }

    /**
     * Enforce Override Governance on Calculated Entries
     */
    public function validateOverride(BudgetEntry $entry, float $newAmount, ?string $reason, string $userId): void
    {
        if ($entry->is_calculated && $entry->amount !== $newAmount) {
            if (empty($reason)) {
                throw new Exception("GovernanceException: Override reason is required when modifying a driver-calculated entry.");
            }

            $entry->override_reason = $reason;
            $entry->override_by = $userId;
            $entry->override_at = now();
        }

        $entry->amount = $newAmount;
        $entry->updated_by = $userId;
    }
}

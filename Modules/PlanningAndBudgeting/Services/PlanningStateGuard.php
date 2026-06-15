<?php

namespace Modules\PlanningAndBudgeting\Services;

use Exception;
use Modules\PlanningAndBudgeting\Models\BudgetCycle;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;

class PlanningStateGuard
{
    /**
     * Enforce strict status transitions for BudgetCycle
     * DRAFT -> OPEN -> IN_REVIEW -> APPROVED -> LOCKED
     */
    public function transitionTo(BudgetCycle $cycle, BudgetCycleStatusEnum $newStatus, string $userId): void
    {
        $current = $cycle->status;

        if ($current === BudgetCycleStatusEnum::Locked) {
            throw new Exception("GovernanceException: Cannot transition from LOCKED state.");
        }

        if ($newStatus === BudgetCycleStatusEnum::Open) {
            if ($current !== BudgetCycleStatusEnum::Draft) {
                throw new Exception("GovernanceException: Invalid transition to OPEN from {$current->value}.");
            }

            $cycle->update([
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);
        } 
        elseif ($newStatus === BudgetCycleStatusEnum::InReview) {
            if ($current !== BudgetCycleStatusEnum::Open) {
                throw new Exception("GovernanceException: Invalid transition to IN_REVIEW from {$current->value}.");
            }

            $cycle->update([
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);
        }
        elseif ($newStatus === BudgetCycleStatusEnum::Approved) {
            if ($current !== BudgetCycleStatusEnum::InReview) {
                throw new Exception("GovernanceException: Invalid transition to APPROVED from {$current->value}.");
            }

            // Creator != Approver Governance
            if ($cycle->created_by === $userId) {
                throw new Exception("GovernanceException: Creator cannot approve their own Budget Cycle.");
            }

            $cycle->update([
                'status' => $newStatus,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);
        }
        elseif ($newStatus === BudgetCycleStatusEnum::Locked) {
            if ($current !== BudgetCycleStatusEnum::Approved) {
                throw new Exception("GovernanceException: Invalid transition to LOCKED from {$current->value}.");
            }

            $cycle->update([
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);
        }
        else {
            throw new Exception("GovernanceException: Unsupported transition.");
        }
    }
}

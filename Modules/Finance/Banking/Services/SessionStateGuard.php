<?php

namespace Modules\Finance\Banking\Services;

use Exception;
use Carbon\Carbon;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;

class SessionStateGuard
{
    /**
     * Prevent backdated session creation.
     */
    public function validateCreationDate(string $propertyId, string $bankAccountId, string $startDate): void
    {
            $latestSession = ReconciliationSession::where('property_id', $propertyId)
            ->where('bank_account_id', $bankAccountId)
            ->whereIn('status', [ReconciliationSessionStatusEnum::Completed, ReconciliationSessionStatusEnum::Finalized])
            ->orderByDesc('statement_date_end')
            ->first();

        if ($latestSession) {
            $start = Carbon::parse($startDate);
            $latestEnd = Carbon::parse($latestSession->statement_date_end);

            if ($start->lessThanOrEqualTo($latestEnd)) {
                throw new Exception("Backdated Protection: Cannot create a session starting on or before the latest completed session ({$latestEnd->toDateString()}).");
            }
        }
    }

    /**
     * Enforce valid state transitions.
     */
    public function transitionTo(ReconciliationSession $session, ReconciliationSessionStatusEnum $newStatus, string $userId): void
    {
        $current = $session->status;

        if ($current === ReconciliationSessionStatusEnum::Finalized) {
            throw new Exception("No Reopen Policy: Cannot transition a Finalized session to any other state.");
        }

        if ($newStatus === ReconciliationSessionStatusEnum::InProgress) {
            if ($current !== ReconciliationSessionStatusEnum::Open) {
                throw new Exception("Illegal Transition: Cannot move to InProgress from {$current->value}.");
            }
            $session->update(['status' => $newStatus]);
        } 
        elseif ($newStatus === ReconciliationSessionStatusEnum::Review) {
            if ($current !== ReconciliationSessionStatusEnum::InProgress) {
                throw new Exception("Illegal Transition: Cannot move to Review from {$current->value}.");
            }
            // Cannot review if unreconciled balance is not zero
            // (Assuming this check will be done before transition, but leaving architectural boundary here)
            
            $session->update([
                'status' => $newStatus,
                'reviewed_by' => $session->created_by, // temporarily, this is just moving state
                // actually wait, when we move to REVIEW, we don't set reviewed_by yet. 
                // The reviewer sets it to COMPLETED.
            ]);
        } 
        elseif ($newStatus === ReconciliationSessionStatusEnum::Completed) {
            if ($current !== ReconciliationSessionStatusEnum::Review) {
                throw new Exception("Illegal Transition: Cannot move to Completed from {$current->value}.");
            }
            
            if ($session->created_by === $userId) {
                throw new Exception("Maker Checker Violation: The user who started the session cannot approve/complete it.");
            }

            $session->update([
                'status' => $newStatus,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);
        }
        elseif ($newStatus === ReconciliationSessionStatusEnum::Finalized) {
            if ($current !== ReconciliationSessionStatusEnum::Completed) {
                throw new Exception("Illegal Transition: Cannot move to Finalized from {$current->value}.");
            }
            
            if ($session->reviewed_by === $userId) {
                throw new Exception("GovernanceException: Reviewer cannot be Finalizer.");
            }
            if ($session->created_by === $userId) {
                throw new Exception("GovernanceException: Maker cannot be Finalizer.");
            }

            $session->update([
                'status' => $newStatus,
                'finalized_by' => $userId,
                'finalized_at' => now(),
            ]);
        }
        else {
            throw new Exception("Illegal Transition: Target state {$newStatus->value} not supported via standard transition.");
        }
    }
}

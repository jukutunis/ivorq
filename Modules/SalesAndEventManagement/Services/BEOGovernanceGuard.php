<?php

namespace Modules\SalesAndEventManagement\Services;

use Exception;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;
use Modules\SalesAndEventManagement\Enums\BEOStatusEnum;

class BEOGovernanceGuard
{
    /**
     * Enforce Creator cannot be the same as Approver
     */
    public function enforceCreatorIsNotApprover(BEOIssueLog $beo, string $approverId): void
    {
        if ($beo->created_by === $approverId) {
            throw new Exception("Governance Violation: Creator cannot approve their own BEO.");
        }
    }

    /**
     * Enforce Immutability on Published BEOs
     */
    public function enforceImmutability(BEOIssueLog $beo): void
    {
        // If the BEO is already published, it cannot be modified
        if (in_array($beo->status, [BEOStatusEnum::PUBLISHED, BEOStatusEnum::SUPERSEDED, BEOStatusEnum::CANCELLED])) {
            throw new Exception("Governance Violation: Cannot modify an issued or superseded BEO.");
        }
    }

    /**
     * Enforce Revision Chain Integrity
     */
    public function enforceRevisionChainIntegrity(BEOIssueLog $newBeo, ?BEOIssueLog $previousBeo): void
    {
        if ($previousBeo) {
            if ($newBeo->function_id !== $previousBeo->function_id) {
                throw new Exception("Governance Violation: Revision chain must belong to the same Function.");
            }
            if ($newBeo->revision_number <= $previousBeo->revision_number) {
                throw new Exception("Governance Violation: New revision number must be greater than previous revision.");
            }
        } else {
            if ($newBeo->revision_number !== 0) {
                throw new Exception("Governance Violation: Initial BEO issue must have revision number 0.");
            }
        }
    }

    /**
     * Enforce Property Isolation
     */
    public function enforcePropertyIsolation(string $contextPropertyId, string $targetPropertyId): void
    {
        if ($contextPropertyId !== $targetPropertyId) {
            throw new Exception("Governance Violation: Property isolation breached.");
        }
    }
}

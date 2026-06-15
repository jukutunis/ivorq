<?php

namespace Modules\SalesAndEventManagement\Services;

use Exception;
use Modules\SalesAndEventManagement\Models\Proposal;
use Modules\SalesAndEventManagement\Models\ProposalRevision;

class ProposalGovernanceGuard
{
    /**
     * Create a new revision for a Proposal, ensuring immutability.
     */
    public function createRevision(Proposal $proposal, string $details, string $userId): ProposalRevision
    {
        $latestRevisionNumber = $proposal->revisions()->max('revision_number') ?? 0;
        $newRevisionNumber = $latestRevisionNumber + 1;

        return ProposalRevision::create([
            'proposal_id' => $proposal->id,
            'revision_number' => $newRevisionNumber,
            'details' => $details,
            'created_by' => $userId,
        ]);
    }

    /**
     * Prevent modification of existing revisions.
     */
    public function validateImmutability(ProposalRevision $revision): void
    {
        throw new Exception("GovernanceException: Proposal Revisions are immutable and cannot be modified once generated.");
    }
}

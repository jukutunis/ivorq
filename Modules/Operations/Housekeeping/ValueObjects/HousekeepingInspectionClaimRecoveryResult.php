<?php

namespace Modules\Operations\Housekeeping\ValueObjects;

use Modules\Operations\Housekeeping\Models\HousekeepingInspectionClaimReassignment;

final readonly class HousekeepingInspectionClaimRecoveryResult
{
    public function __construct(
        public HousekeepingInspectionClaimReassignment $reassignment,
        public bool $replayed,
    ) {}

    /** @return array{reassignment_id: string, effective_claimant_id: string, original_ineligibility_code: string, reason: string, occurred_at: mixed, evidence_version: int, replayed: bool} */
    public function toArray(): array
    {
        return [
            'reassignment_id' => $this->reassignment->id,
            'effective_claimant_id' => $this->reassignment->replacement_claimant_id,
            'original_ineligibility_code' => $this->reassignment->original_ineligibility_code,
            'reason' => $this->reassignment->reason,
            'occurred_at' => $this->reassignment->occurred_at,
            'evidence_version' => $this->reassignment->evidence_version,
            'replayed' => $this->replayed,
        ];
    }
}

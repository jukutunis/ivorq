<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Foundation\User\Models\User;

class JournalCandidateReviewService
{
    /**
     * Approve a PENDING_REVIEW journal candidate.
     */
    public function approve(string $id, string $userId): JournalCandidate
    {
        return DB::transaction(function () use ($id, $userId) {
            $candidate = JournalCandidate::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Resolve and validate the actor
            $user = User::findOrFail($userId);
            if (!$user->can('finance.journal-candidate.review') && !$user->can('general-ledger.journal-candidate.review')) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Unauthorized to review journal candidates.");
            }

            // 2. Safe idempotent retry / conflict check
            if ($candidate->status === JournalCandidateStatusEnum::APPROVED) {
                if ($candidate->approved_by === $userId) {
                    return $candidate;
                }
                throw new \RuntimeException("Conflicting review payload: already approved by another user.");
            }

            if ($candidate->status === JournalCandidateStatusEnum::REJECTED) {
                throw new \RuntimeException("Conflicting review payload: already rejected.");
            }

            if ($candidate->status === JournalCandidateStatusEnum::POSTED || $candidate->status === JournalCandidateStatusEnum::POSTED_LEGACY) {
                throw new \RuntimeException("Conflicting review payload: candidate is already posted.");
            }

            // 3. Verify exact pending-review status
            if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
                throw new \RuntimeException("Only PENDING_REVIEW candidates can be approved.");
            }

            // 4. Update status and evidence atomically
            $candidate->update([
                'status' => JournalCandidateStatusEnum::APPROVED->value,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $candidate;
        });
    }

    /**
     * Reject a PENDING_REVIEW journal candidate with a reason.
     */
    public function reject(string $id, string $reason, string $userId): JournalCandidate
    {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException("A rejection reason is mandatory.");
        }

        return DB::transaction(function () use ($id, $reason, $userId) {
            $candidate = JournalCandidate::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Resolve and validate the actor
            $user = User::findOrFail($userId);
            if (!$user->can('finance.journal-candidate.review') && !$user->can('general-ledger.journal-candidate.review')) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Unauthorized to review journal candidates.");
            }

            // 2. Safe idempotent retry / conflict check
            if ($candidate->status === JournalCandidateStatusEnum::REJECTED) {
                if ($candidate->rejected_by === $userId && $candidate->rejection_reason === $reason) {
                    return $candidate;
                }
                throw new \RuntimeException("Conflicting review payload: already rejected with a different reason or actor.");
            }

            if ($candidate->status === JournalCandidateStatusEnum::APPROVED) {
                throw new \RuntimeException("Conflicting review payload: already approved.");
            }

            if ($candidate->status === JournalCandidateStatusEnum::POSTED || $candidate->status === JournalCandidateStatusEnum::POSTED_LEGACY) {
                throw new \RuntimeException("Conflicting review payload: candidate is already posted.");
            }

            // 3. Verify exact pending-review status
            if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
                throw new \RuntimeException("Only PENDING_REVIEW candidates can be rejected.");
            }

            // 4. Update status and evidence atomically
            $candidate->update([
                'status' => JournalCandidateStatusEnum::REJECTED->value,
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $candidate;
        });
    }
}

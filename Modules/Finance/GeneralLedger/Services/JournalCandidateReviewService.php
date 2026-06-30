<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Foundation\User\Models\User;
use Throwable;

class JournalCandidateReviewService
{
    public const PERMISSION = 'finance.journal-candidate.review';

    /**
     * Approve a PENDING_REVIEW journal candidate.
     */
    public function approve(string $id, string $userId): JournalCandidate
    {
        return DB::transaction(function () use ($id, $userId) {
            $user = $this->resolveAuthorizedActor($userId);

            $candidate = JournalCandidate::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($user, $candidate->property_id);

            // 2. Safe idempotent retry / conflict check
            if ($candidate->status === JournalCandidateStatusEnum::APPROVED) {
                if ($candidate->approved_by === $user->id) {
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
                'approved_by' => $user->id,
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
            $user = $this->resolveAuthorizedActor($userId);

            $candidate = JournalCandidate::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($user, $candidate->property_id);

            // 2. Safe idempotent retry / conflict check
            if ($candidate->status === JournalCandidateStatusEnum::REJECTED) {
                if ($candidate->rejected_by === $user->id && $candidate->rejection_reason === $reason) {
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
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $candidate;
        });
    }

    private function resolveAuthorizedActor(string $userId): User
    {
        $user = User::where('id', $userId)
            ->where('is_active', true)
            ->first();

        if (!$user) {
            throw new AuthorizationException('Unauthorized to review journal candidates.');
        }

        try {
            $authorized = $user->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Unauthorized to review journal candidates.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Unauthorized to review journal candidates.');
        }

        return $user;
    }

    private function assertActorCanAccessProperty(User $user, string $propertyId): void
    {
        $hasPropertyAccess = $user->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Unauthorized to review journal candidates.');
        }
    }
}

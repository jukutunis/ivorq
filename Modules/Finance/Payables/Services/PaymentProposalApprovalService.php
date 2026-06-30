<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Foundation\User\Models\User;
use Throwable;

class PaymentProposalApprovalService
{
    public const SUBMIT_PERMISSION = 'finance.payables.payment-proposal.submit';
    public const APPROVE_PERMISSION = 'finance.payables.payment-proposal.approve';

    public function submit(string $proposalId, ?User $actor): PaymentProposal
    {
        return DB::transaction(function () use ($proposalId, $actor) {
            $actor = $this->resolveAuthorizedActor($actor, self::SUBMIT_PERMISSION);

            $proposal = PaymentProposal::whereKey($proposalId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $proposal->property_id);

            if ($proposal->status === PaymentProposalStatusEnum::PENDING_APPROVAL) {
                if ($proposal->submitted_by === $actor->id) {
                    return $proposal->fresh(['items']);
                }

                throw new DomainException('Conflicting Payment Proposal submission evidence already exists.');
            }

            if ($proposal->status !== PaymentProposalStatusEnum::DRAFT) {
                throw new DomainException('Only Draft Payment Proposals can be submitted for approval.');
            }

            $proposal->status = PaymentProposalStatusEnum::PENDING_APPROVAL;
            $proposal->submitted_by = $actor->id;
            $proposal->submitted_at = now();
            $proposal->updated_by = $actor->id;
            $proposal->save();

            return $proposal->fresh(['items']);
        });
    }

    public function approve(string $proposalId, ?User $actor): PaymentProposal
    {
        return DB::transaction(function () use ($proposalId, $actor) {
            $actor = $this->resolveAuthorizedActor($actor, self::APPROVE_PERMISSION);

            $proposal = PaymentProposal::whereKey($proposalId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $proposal->property_id);

            if ($proposal->status === PaymentProposalStatusEnum::APPROVED) {
                if ($proposal->approved_by === $actor->id) {
                    return $proposal->fresh(['items']);
                }

                throw new DomainException('Conflicting Payment Proposal approval evidence already exists.');
            }

            if ($proposal->status === PaymentProposalStatusEnum::REJECTED) {
                throw new DomainException('Rejected Payment Proposals cannot be approved.');
            }

            if ($proposal->status !== PaymentProposalStatusEnum::PENDING_APPROVAL) {
                throw new DomainException('Only Pending Approval Payment Proposals can be approved.');
            }

            if ($proposal->created_by === $actor->id) {
                throw new AuthorizationException('Payment Proposal creator cannot approve their own proposal.');
            }

            $proposal->status = PaymentProposalStatusEnum::APPROVED;
            $proposal->approved_by = $actor->id;
            $proposal->approved_at = now();
            $proposal->updated_by = $actor->id;
            $proposal->save();

            return $proposal->fresh(['items']);
        });
    }

    public function reject(string $proposalId, ?User $actor, string $reason): PaymentProposal
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Payment Proposal rejection requires a meaningful reason.');
        }

        return DB::transaction(function () use ($proposalId, $actor, $reason) {
            $actor = $this->resolveAuthorizedActor($actor, self::APPROVE_PERMISSION);

            $proposal = PaymentProposal::whereKey($proposalId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $proposal->property_id);

            if ($proposal->status === PaymentProposalStatusEnum::REJECTED) {
                if ($proposal->rejected_by === $actor->id && $proposal->rejection_reason === $reason) {
                    return $proposal->fresh(['items']);
                }

                throw new DomainException('Conflicting Payment Proposal rejection evidence already exists.');
            }

            if ($proposal->status === PaymentProposalStatusEnum::APPROVED) {
                throw new DomainException('Approved Payment Proposals cannot be rejected.');
            }

            if ($proposal->status !== PaymentProposalStatusEnum::PENDING_APPROVAL) {
                throw new DomainException('Only Pending Approval Payment Proposals can be rejected.');
            }

            $proposal->status = PaymentProposalStatusEnum::REJECTED;
            $proposal->rejected_by = $actor->id;
            $proposal->rejected_at = now();
            $proposal->rejection_reason = $reason;
            $proposal->updated_by = $actor->id;
            $proposal->save();

            return $proposal->fresh(['items']);
        });
    }

    private function resolveAuthorizedActor(?User $actor, string $permission): User
    {
        if (!$actor) {
            throw new AuthorizationException('Payment Proposal approval requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Payment Proposal approval requires an active actor.');
        }

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('Payment Proposal approval permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Payment Proposal approval permission is required.');
        }

        return $freshActor;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Payment Proposal approval requires active property access.');
        }
    }
}

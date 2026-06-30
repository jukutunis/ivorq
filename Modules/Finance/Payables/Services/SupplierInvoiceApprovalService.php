<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Foundation\User\Models\User;
use Throwable;

class SupplierInvoiceApprovalService
{
    public const PERMISSION = 'finance.payables.supplier-invoice.approve';

    public function approve(string $invoiceId, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($invoiceId, $actor): SupplierInvoice {
            $invoice = SupplierInvoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $freshActor = $this->resolveActor($actor, $invoice->property_id);

            if ($invoice->status === SupplierInvoice::STATUS_APPROVED) {
                if ($invoice->approved_by === $freshActor->id) {
                    return $invoice->fresh(['threeWayMatch.lines', 'lines']);
                }

                throw new DomainException('Supplier invoice approval already exists with different evidence.');
            }

            if ($invoice->status === SupplierInvoice::STATUS_REJECTED) {
                throw new DomainException('Supplier invoice rejection is final for this lifecycle slice.');
            }

            $this->assertApprovalAllowed($invoice);

            $invoice->forceFill([
                'status' => SupplierInvoice::STATUS_APPROVED,
                'approved_by' => $freshActor->id,
                'approved_at' => now(),
                'updated_by' => $freshActor->id,
            ])->save();

            return $invoice->fresh(['threeWayMatch.lines', 'lines']);
        });
    }

    public function reject(string $invoiceId, User $actor, string $reason): SupplierInvoice
    {
        $normalizedReason = $this->normalizeReason($reason, 'Supplier invoice rejection reason is required.');

        return DB::transaction(function () use ($invoiceId, $actor, $normalizedReason): SupplierInvoice {
            $invoice = SupplierInvoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $freshActor = $this->resolveActor($actor, $invoice->property_id);

            if ($invoice->status === SupplierInvoice::STATUS_REJECTED) {
                if ($invoice->rejected_by === $freshActor->id &&
                    $invoice->rejection_reason === $normalizedReason) {
                    return $invoice->fresh(['threeWayMatch.lines', 'lines']);
                }

                throw new DomainException('Supplier invoice rejection already exists with different evidence.');
            }

            if ($invoice->status === SupplierInvoice::STATUS_APPROVED) {
                throw new DomainException('Supplier invoice approval is final for this lifecycle slice.');
            }

            $this->assertDecisionAllowed($invoice);

            $invoice->forceFill([
                'status' => SupplierInvoice::STATUS_REJECTED,
                'rejected_by' => $freshActor->id,
                'rejected_at' => now(),
                'rejection_reason' => $normalizedReason,
                'updated_by' => $freshActor->id,
            ])->save();

            return $invoice->fresh(['threeWayMatch.lines', 'lines']);
        });
    }

    private function assertApprovalAllowed(SupplierInvoice $invoice): void
    {
        $match = $this->matchForDecision($invoice);

        if ($match->status === MatchStatusEnum::Matched) {
            return;
        }

        if ($match->status === MatchStatusEnum::Exception && $invoice->exception_resolved_at !== null) {
            return;
        }

        throw new DomainException('Supplier invoice exception must be resolved before approval.');
    }

    private function assertDecisionAllowed(SupplierInvoice $invoice): void
    {
        $this->matchForDecision($invoice);
    }

    private function matchForDecision(SupplierInvoice $invoice): object
    {
        $match = $invoice->threeWayMatch()->first();

        if (!$match || !in_array($match->status, [MatchStatusEnum::Matched, MatchStatusEnum::Exception], true)) {
            throw new DomainException('Supplier invoice decision requires a matched or exception match result.');
        }

        return $match;
    }

    private function resolveActor(User $actor, string $propertyId): User
    {
        $freshActor = User::query()->find($actor->id);

        if (!$freshActor || !$freshActor->is_active) {
            throw new AuthorizationException('Supplier invoice approval actor is inactive or unresolved.');
        }

        try {
            $hasPermission = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Supplier invoice approval permission is unavailable.');
        }

        if (!$hasPermission) {
            throw new AuthorizationException('Supplier invoice approval permission is required.');
        }

        $hasPropertyAccess = $freshActor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Supplier invoice approval requires active property access.');
        }

        return $freshActor;
    }

    private function normalizeReason(string $reason, string $message): string
    {
        $normalized = trim($reason);

        if ($normalized === '') {
            throw new DomainException($message);
        }

        return $normalized;
    }
}

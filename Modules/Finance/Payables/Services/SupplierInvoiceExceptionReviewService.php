<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Foundation\User\Models\User;
use Throwable;

class SupplierInvoiceExceptionReviewService
{
    public const PERMISSION = 'finance.payables.supplier-invoice.review-exception';

    public function resolveException(string $invoiceId, User $actor, string $reason): SupplierInvoice
    {
        $normalizedReason = $this->normalizeReason($reason, 'Supplier invoice exception resolution reason is required.');

        return DB::transaction(function () use ($invoiceId, $actor, $normalizedReason): SupplierInvoice {
            $invoice = SupplierInvoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $freshActor = $this->resolveActor($actor, $invoice->property_id);
            $match = $invoice->threeWayMatch()->with('lines')->first();

            if ($invoice->exception_resolved_at !== null) {
                if ($invoice->exception_resolved_by === $freshActor->id &&
                    $invoice->exception_resolution_reason === $normalizedReason) {
                    return $invoice->fresh(['threeWayMatch.lines', 'lines']);
                }

                throw new DomainException('Supplier invoice exception resolution already exists with different evidence.');
            }

            if (in_array($invoice->status, [SupplierInvoice::STATUS_APPROVED, SupplierInvoice::STATUS_REJECTED], true)) {
                throw new DomainException('Supplier invoice terminal state cannot receive exception resolution evidence.');
            }

            if (!$match || $match->status !== MatchStatusEnum::Exception) {
                throw new DomainException('Supplier invoice exception resolution requires an exception match result.');
            }

            $invoice->forceFill([
                'exception_resolved_by' => $freshActor->id,
                'exception_resolved_at' => now(),
                'exception_resolution_reason' => $normalizedReason,
                'updated_by' => $freshActor->id,
            ])->save();

            return $invoice->fresh(['threeWayMatch.lines', 'lines']);
        });
    }

    private function resolveActor(User $actor, string $propertyId): User
    {
        $freshActor = User::query()->find($actor->id);

        if (!$freshActor || !$freshActor->is_active) {
            throw new AuthorizationException('Supplier invoice exception review actor is inactive or unresolved.');
        }

        try {
            $hasPermission = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Supplier invoice exception review permission is unavailable.');
        }

        if (!$hasPermission) {
            throw new AuthorizationException('Supplier invoice exception review permission is required.');
        }

        $hasPropertyAccess = $freshActor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Supplier invoice exception review requires active property access.');
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

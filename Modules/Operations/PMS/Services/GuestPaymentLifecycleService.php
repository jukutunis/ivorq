<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Events\GuestPaymentAllocated;
use Modules\Operations\PMS\Events\GuestPaymentAllocationReversed;
use Modules\Operations\PMS\Events\GuestPaymentRecorded;
use Modules\Operations\PMS\Events\GuestPaymentVoided;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GuestPaymentLifecycleService
{
    public const RECORD_PERMISSION = 'pms.cashiering.guest-payment.record';
    public const ALLOCATE_PERMISSION = 'pms.cashiering.guest-payment.allocate';
    public const VOID_PERMISSION = 'pms.cashiering.guest-payment.void';
    public const REVERSAL_PERMISSION = 'pms.cashiering.guest-payment.reverse';

    public const VOID_CONFIRMATION_INTENT = 'pms-guest-payment-void';
    public const REVERSAL_CONFIRMATION_INTENT = 'pms-guest-payment-reversal';

    private const IDEMPOTENCY_KEY_MAX_LENGTH = 96;

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
        private readonly GuestLedgerPaymentAllocationEffectService $effectService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function recordCashPayment(
        User $actor,
        string $reservationId,
        string $cashierSessionId,
        string $amount,
        string $idempotencyKey
    ): GuestPaymentTransaction {
        $propertyId = $this->resolveCurrentProperty();
        $actor = $this->resolveAuthorizedActor($actor, self::RECORD_PERMISSION, $propertyId);
        $amount = $this->normalisePositiveAmount($amount, 'amount');
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $propertyId, $reservationId, $cashierSessionId, $amount, $idempotencyKey): GuestPaymentTransaction {
            $property = Property::whereKey($propertyId)->lockForUpdate()->firstOrFail();

            $reservation = Reservation::withoutGlobalScope('property')
                ->whereKey($reservationId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$reservation->primary_guest_id) {
                throw new DomainException('Reservation primary guest is unavailable for guest payment.');
            }

            $session = CashierSession::whereKey($cashierSessionId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCashierSessionUsable($session, $actor);

            $existing = GuestPaymentTransaction::where('property_id', $propertyId)
                ->where('recording_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingPaymentMatches($existing, $reservation, $session, $amount, $actor->id, $property->currency);
                return $existing->fresh();
            }

            $payment = new GuestPaymentTransaction();
            $payment->forceFill([
                'property_id' => $propertyId,
                'payment_number' => $this->generatePaymentNumberLocked($propertyId),
                'reservation_id' => $reservation->id,
                'guest_id' => $reservation->primary_guest_id,
                'currency' => $property->currency,
                'amount' => $amount,
                'tender_type' => GuestPaymentTenderTypeEnum::Cash,
                'cashier_session_id' => $session->id,
                'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded,
                'recording_idempotency_key' => $idempotencyKey,
                'recorded_at' => now(),
                'recorded_by' => $actor->id,
                'source_snapshot' => [
                    'cashier_session_id' => $session->id,
                    'cashier_user_id' => $session->cashier_user_id,
                    'cashier_session_status' => $session->status->value,
                    'opened_at' => $session->opened_at?->toISOString(),
                    'opened_by' => $session->opened_by,
                    'reservation_id' => $reservation->id,
                    'guest_id' => $reservation->primary_guest_id,
                    'currency' => $property->currency,
                    'tender_type' => GuestPaymentTenderTypeEnum::Cash->value,
                ],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            DB::afterCommit(fn () => event(new GuestPaymentRecorded($payment->fresh())));

            return $payment->fresh();
        });
    }

    public function allocatePayment(
        User $actor,
        string $paymentId,
        string $folioId,
        string $amount,
        string $idempotencyKey
    ): GuestPaymentAllocation {
        $propertyId = $this->resolveCurrentProperty();
        $actor = $this->resolveAuthorizedActor($actor, self::ALLOCATE_PERMISSION, $propertyId);
        $amount = $this->normalisePositiveAmount($amount, 'amount');
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $propertyId, $paymentId, $folioId, $amount, $idempotencyKey): GuestPaymentAllocation {
            $payment = GuestPaymentTransaction::whereKey($paymentId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $folio = Folio::withoutGlobalScope('property')
                ->whereKey($folioId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPaymentCanAllocate($payment, $folio);

            $existing = GuestPaymentAllocation::where('property_id', $propertyId)
                ->where('allocation_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingAllocationMatches($existing, $payment, $folio, $amount, $actor->id);
                return $existing->fresh();
            }

            $activeTotal = $this->activeAllocatedAmount($payment);
            $refundedTotal = $this->completedRefundAmount($payment);
            if (bccomp(bcadd(bcadd($activeTotal, $refundedTotal, 2), $amount, 2), $this->amountString($payment->amount), 2) > 0) {
                throw new DomainException('GUEST_PAYMENT_OVER_ALLOCATION');
            }

            $allocation = new GuestPaymentAllocation();
            $allocation->forceFill([
                'property_id' => $propertyId,
                'guest_payment_transaction_id' => $payment->id,
                'folio_id' => $folio->id,
                'amount' => $amount,
                'allocation_idempotency_key' => $idempotencyKey,
                'allocated_at' => now(),
                'allocated_by' => $actor->id,
                'source_snapshot' => [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'reservation_id' => $payment->reservation_id,
                    'folio_id' => $folio->id,
                    'folio_number' => $folio->folio_number,
                    'currency' => $payment->currency,
                    'amount' => $amount,
                ],
                'created_at' => now(),
            ])->save();

            $this->effectService->createAllocationItemLocked($allocation, $payment, $folio);
            $this->refreshPaymentStatus($payment);

            DB::afterCommit(fn () => event(new GuestPaymentAllocated($allocation->fresh())));

            return $allocation->fresh();
        });
    }

    public function voidPayment(
        User $actor,
        string $paymentId,
        string $reasonCode,
        string $idempotencyKey
    ): GuestPaymentReversal {
        $propertyId = $this->resolveCurrentProperty();
        $actor = $this->resolveAuthorizedActor($actor, self::VOID_PERMISSION, $propertyId);
        $reasonCode = $this->validateReasonCode($reasonCode);
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        $this->confirmationService->requireValidConfirmation($actor, self::VOID_CONFIRMATION_INTENT, $this->activeCompanyId(), $propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $paymentId, $reasonCode, $idempotencyKey): GuestPaymentReversal {
            $payment = GuestPaymentTransaction::whereKey($paymentId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = GuestPaymentReversal::where('property_id', $propertyId)
                ->where('reversal_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingVoidMatches($existing, $payment, $reasonCode, $actor->id);
                return $existing->fresh();
            }

            if ($payment->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided) {
                throw new DomainException('Guest payment is already voided.');
            }

            if (GuestPaymentAllocation::where('property_id', $propertyId)->where('guest_payment_transaction_id', $payment->id)->exists()) {
                throw new DomainException('Guest payment with allocation history cannot be voided.');
            }

            $reversal = new GuestPaymentReversal();
            $reversal->forceFill([
                'property_id' => $propertyId,
                'guest_payment_transaction_id' => $payment->id,
                'guest_payment_allocation_id' => null,
                'reversal_type' => GuestPaymentReversalTypeEnum::PaymentVoid,
                'amount' => $this->amountString($payment->amount),
                'reason_code' => $reasonCode,
                'reversal_idempotency_key' => $idempotencyKey,
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'source_snapshot' => [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'amount' => $this->amountString($payment->amount),
                    'reason_code' => $reasonCode,
                    'cashier_session_id' => $payment->cashier_session_id,
                ],
                'created_at' => now(),
            ])->save();

            $payment->forceFill([
                'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Voided,
                'updated_by' => $actor->id,
            ])->save();

            DB::afterCommit(fn () => event(new GuestPaymentVoided($reversal->fresh())));

            return $reversal->fresh();
        });
    }

    public function reverseAllocation(
        User $actor,
        string $allocationId,
        string $reasonCode,
        string $idempotencyKey
    ): GuestPaymentReversal {
        $propertyId = $this->resolveCurrentProperty();
        $actor = $this->resolveAuthorizedActor($actor, self::REVERSAL_PERMISSION, $propertyId);
        $reasonCode = $this->validateReasonCode($reasonCode);
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);
        $this->confirmationService->requireValidConfirmation($actor, self::REVERSAL_CONFIRMATION_INTENT, $this->activeCompanyId(), $propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $allocationId, $reasonCode, $idempotencyKey): GuestPaymentReversal {
            $identity = GuestPaymentAllocation::whereKey($allocationId)
                ->where('property_id', $propertyId)
                ->firstOrFail();

            $payment = GuestPaymentTransaction::whereKey($identity->guest_payment_transaction_id)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $folio = Folio::withoutGlobalScope('property')
                ->whereKey($identity->folio_id)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $allocation = GuestPaymentAllocation::whereKey($allocationId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = GuestPaymentReversal::where('property_id', $propertyId)
                ->where('reversal_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingAllocationReversalMatches($existing, $allocation, $payment, $reasonCode, $actor->id);
                return $existing->fresh();
            }

            if (GuestPaymentReversal::where('property_id', $propertyId)
                ->where('guest_payment_allocation_id', $allocation->id)
                ->where('reversal_type', GuestPaymentReversalTypeEnum::AllocationReversal->value)
                ->exists()) {
                throw new DomainException('Guest payment allocation has already been reversed.');
            }

            $reversal = new GuestPaymentReversal();
            $reversal->forceFill([
                'property_id' => $propertyId,
                'guest_payment_transaction_id' => $payment->id,
                'guest_payment_allocation_id' => $allocation->id,
                'reversal_type' => GuestPaymentReversalTypeEnum::AllocationReversal,
                'amount' => $this->amountString($allocation->amount),
                'reason_code' => $reasonCode,
                'reversal_idempotency_key' => $idempotencyKey,
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'source_snapshot' => [
                    'payment_id' => $payment->id,
                    'allocation_id' => $allocation->id,
                    'folio_id' => $allocation->folio_id,
                    'amount' => $this->amountString($allocation->amount),
                    'reason_code' => $reasonCode,
                ],
                'created_at' => now(),
            ])->save();

            $this->effectService->createReversalItemLocked($reversal, $allocation, $payment, $folio);
            $this->refreshPaymentStatus($payment);

            DB::afterCommit(fn () => event(new GuestPaymentAllocationReversed($reversal->fresh())));

            return $reversal->fresh();
        });
    }

    private function assertPaymentCanAllocate(GuestPaymentTransaction $payment, Folio $folio): void
    {
        if ($payment->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided) {
            throw new DomainException('Voided guest payments cannot be allocated.');
        }

        if ($folio->status !== FolioStatusEnum::Open) {
            throw new DomainException('Guest payments can only be allocated to open Folios.');
        }

        if (
            $payment->property_id !== $folio->property_id ||
            $payment->reservation_id !== $folio->reservation_id ||
            $payment->currency !== $folio->currency
        ) {
            throw new DomainException('Guest payment and Folio source evidence do not match.');
        }
    }

    private function activeAllocatedAmount(GuestPaymentTransaction $payment): string
    {
        $allocated = GuestPaymentAllocation::where('property_id', $payment->property_id)
            ->where('guest_payment_transaction_id', $payment->id)
            ->get();

        $total = '0.00';
        foreach ($allocated as $allocation) {
            $reversed = GuestPaymentReversal::where('property_id', $payment->property_id)
                ->where('guest_payment_allocation_id', $allocation->id)
                ->where('reversal_type', GuestPaymentReversalTypeEnum::AllocationReversal->value)
                ->exists();

            if (!$reversed) {
                $total = bcadd($total, $this->amountString($allocation->amount), 2);
            }
        }

        return $total;
    }

    private function refreshPaymentStatus(GuestPaymentTransaction $payment): void
    {
        $active = $this->activeAllocatedAmount($payment);
        $amount = $this->amountString($payment->amount);

        $status = match (true) {
            bccomp($active, '0.00', 2) === 0 => GuestPaymentLifecycleStatusEnum::Recorded,
            bccomp($active, $amount, 2) < 0 => GuestPaymentLifecycleStatusEnum::PartiallyAllocated,
            default => GuestPaymentLifecycleStatusEnum::FullyAllocated,
        };

        $payment->forceFill(['lifecycle_status' => $status])->save();
    }

    private function completedRefundAmount(GuestPaymentTransaction $payment): string
    {
        $total = '0.00';
        foreach (GuestRefundTransaction::where('property_id', $payment->property_id)
            ->where('guest_payment_transaction_id', $payment->id)
            ->get() as $refund) {
            $total = bcadd($total, $this->amountString($refund->amount), 2);
        }

        return $total;
    }

    private function assertCashierSessionUsable(CashierSession $session, User $actor): void
    {
        if ($session->status !== CashierSessionStatusEnum::OPEN) {
            throw new DomainException('Cashier Session must be OPEN for guest cash payment.');
        }

        if ($session->cashier_user_id !== $actor->id) {
            throw new AuthorizationException('Guest cash payment requires the active cashier session owner.');
        }
    }

    private function resolveAuthorizedActor(User $actor, string $permission, string $propertyId): User
    {
        if (!auth()->check() || auth()->id() !== $actor->id) {
            throw new AuthorizationException('Guest payment actor must match the authenticated session.');
        }

        $freshActor = User::whereKey($actor->id)->where('is_active', true)->first();
        if (!$freshActor) {
            throw new AuthorizationException('Guest payment requires an active actor.');
        }

        $hasPropertyAccess = $freshActor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Guest payment requires active property access.');
        }

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('Guest payment permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Guest payment permission is required.');
        }

        return $freshActor;
    }

    private function resolveCurrentProperty(): string
    {
        $propertyId = session('active_property_id') ?? session('current_property_id') ?? $this->currentProperty->resolveOrFail();
        $this->currentProperty->setPropertyId($propertyId);

        return $propertyId;
    }

    private function activeCompanyId(): ?string
    {
        return session('active_company_id');
    }

    private function generatePaymentNumberLocked(string $propertyId): string
    {
        $seq = GuestPaymentTransaction::where('property_id', $propertyId)->count() + 1;
        return sprintf('GPM-%05d', $seq);
    }

    private function assertExistingPaymentMatches(GuestPaymentTransaction $existing, Reservation $reservation, CashierSession $session, string $amount, string $actorId, string $currency): void
    {
        if (
            $existing->reservation_id === $reservation->id &&
            $existing->cashier_session_id === $session->id &&
            $this->amountString($existing->amount) === $amount &&
            $existing->currency === $currency &&
            $existing->tender_type === GuestPaymentTenderTypeEnum::Cash &&
            $existing->recorded_by === $actorId
        ) {
            return;
        }

        throw new DomainException('GUEST_PAYMENT_IDEMPOTENCY_CONFLICT');
    }

    private function assertExistingAllocationMatches(GuestPaymentAllocation $existing, GuestPaymentTransaction $payment, Folio $folio, string $amount, string $actorId): void
    {
        if (
            $existing->guest_payment_transaction_id === $payment->id &&
            $existing->folio_id === $folio->id &&
            $this->amountString($existing->amount) === $amount &&
            $existing->allocated_by === $actorId
        ) {
            return;
        }

        throw new DomainException('GUEST_PAYMENT_ALLOCATION_IDEMPOTENCY_CONFLICT');
    }

    private function assertExistingVoidMatches(GuestPaymentReversal $existing, GuestPaymentTransaction $payment, string $reasonCode, string $actorId): void
    {
        if (
            $existing->guest_payment_transaction_id === $payment->id &&
            $existing->guest_payment_allocation_id === null &&
            $existing->reversal_type === GuestPaymentReversalTypeEnum::PaymentVoid &&
            $existing->reason_code === $reasonCode &&
            $existing->reversed_by === $actorId
        ) {
            return;
        }

        throw new DomainException('GUEST_PAYMENT_VOID_IDEMPOTENCY_CONFLICT');
    }

    private function assertExistingAllocationReversalMatches(GuestPaymentReversal $existing, GuestPaymentAllocation $allocation, GuestPaymentTransaction $payment, string $reasonCode, string $actorId): void
    {
        if (
            $existing->guest_payment_transaction_id === $payment->id &&
            $existing->guest_payment_allocation_id === $allocation->id &&
            $existing->reversal_type === GuestPaymentReversalTypeEnum::AllocationReversal &&
            $existing->reason_code === $reasonCode &&
            $existing->reversed_by === $actorId
        ) {
            return;
        }

        throw new DomainException('GUEST_PAYMENT_REVERSAL_IDEMPOTENCY_CONFLICT');
    }

    private function validateIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > self::IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw ValidationException::withMessages(['idempotency_key' => ['A valid idempotency key is required.']]);
        }

        return $key;
    }

    private function validateReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if ($reasonCode === '' || mb_strlen($reasonCode) > 80) {
            throw ValidationException::withMessages(['reason_code' => ['A valid reason code is required.']]);
        }

        return $reasonCode;
    }

    private function normalisePositiveAmount(mixed $value, string $field): string
    {
        $str = (string) $value;
        if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $str)) {
            throw ValidationException::withMessages([$field => ['Amount must be a plain positive decimal.']]);
        }

        [$intPart, $fracPart] = array_pad(explode('.', $str, 2), 2, '');
        if (strlen($intPart) > 10) {
            throw ValidationException::withMessages([$field => ['Amount exceeds maximum supported precision.']]);
        }

        if (strlen($fracPart) > 2 && rtrim(substr($fracPart, 2), '0') !== '') {
            throw ValidationException::withMessages([$field => ['Amount has too many decimal places.']]);
        }

        $normalised = bcadd($intPart . '.' . str_pad(substr($fracPart, 0, 2), 2, '0'), '0.00', 2);
        if (bccomp($normalised, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([$field => ['Amount must be positive.']]);
        }

        return $normalised;
    }

    private function amountString(mixed $amount): string
    {
        return $this->normalisePositiveAmount((string) $amount, 'amount');
    }
}

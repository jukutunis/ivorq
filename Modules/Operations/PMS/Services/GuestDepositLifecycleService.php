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
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Events\GuestDepositApplied;
use Modules\Operations\PMS\Events\GuestDepositApplicationReversed;
use Modules\Operations\PMS\Events\GuestDepositRecorded;
use Modules\Operations\PMS\Events\GuestDepositVoided;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GuestDepositLifecycleService
{
    public const RECORD_PERMISSION = 'pms.cashiering.guest-deposit.record';
    public const APPLY_PERMISSION = 'pms.cashiering.guest-deposit.apply';
    public const VOID_PERMISSION = 'pms.cashiering.guest-deposit.void';
    public const REVERSE_PERMISSION = 'pms.cashiering.guest-deposit.reverse-application';
    public const VOID_INTENT = 'guest-deposit-void';
    public const REVERSE_INTENT = 'guest-deposit-application-reversal';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
        private readonly GuestLedgerDepositEffectService $effects,
        private readonly SensitiveActionConfirmationService $confirmation,
    ) {}

    public function recordCashDeposit(User $actor, string $reservationId, string $cashierSessionId, string $amount, string $idempotencyKey): GuestDepositTransaction
    {
        $propertyId = $this->propertyId();
        $actor = $this->actor($actor, self::RECORD_PERMISSION, $propertyId);
        $amount = $this->positiveAmount($amount);
        $idempotencyKey = $this->idempotency($idempotencyKey);

        return DB::transaction(function () use ($actor, $reservationId, $cashierSessionId, $amount, $idempotencyKey, $propertyId) {
            $property = Property::whereKey($propertyId)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::withoutGlobalScope('property')->whereKey($reservationId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            if (!$reservation->primary_guest_id) throw new DomainException('Reservation primary guest is unavailable for guest deposit.');
            $session = CashierSession::whereKey($cashierSessionId)->where('property_id', $propertyId)->sharedLock()->firstOrFail();
            $this->assertSession($session, $actor);

            $existing = GuestDepositTransaction::where('property_id', $propertyId)->where('recording_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->reservation_id !== $reservation->id || $existing->cashier_session_id !== $session->id
                    || $this->amount($existing->amount) !== $amount || $existing->recorded_by !== $actor->id
                    || $existing->currency !== $property->currency) throw new DomainException('GUEST_DEPOSIT_IDEMPOTENCY_CONFLICT');
                return $existing->fresh();
            }

            $deposit = new GuestDepositTransaction();
            $deposit->forceFill([
                'property_id' => $propertyId, 'deposit_number' => sprintf('GDP-%05d', GuestDepositTransaction::where('property_id', $propertyId)->count() + 1),
                'reservation_id' => $reservation->id, 'guest_id' => $reservation->primary_guest_id,
                'currency' => $property->currency, 'amount' => $amount, 'tender_type' => GuestPaymentTenderTypeEnum::Cash,
                'cashier_session_id' => $session->id, 'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded,
                'recording_idempotency_key' => $idempotencyKey, 'recorded_at' => now(), 'recorded_by' => $actor->id,
                'source_snapshot' => ['cashier_session_id' => $session->id, 'cashier_user_id' => $session->cashier_user_id,
                    'cashier_session_status' => $session->status->value, 'reservation_id' => $reservation->id,
                    'guest_id' => $reservation->primary_guest_id, 'currency' => $property->currency, 'tender_type' => 'CASH'],
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ])->save();
            DB::afterCommit(fn () => event(new GuestDepositRecorded($deposit->fresh())));
            return $deposit->fresh();
        });
    }

    public function applyDeposit(User $actor, string $depositId, string $folioId, string $amount, string $idempotencyKey): GuestDepositApplication
    {
        $propertyId = $this->propertyId();
        $actor = $this->actor($actor, self::APPLY_PERMISSION, $propertyId);
        $amount = $this->positiveAmount($amount);
        $idempotencyKey = $this->idempotency($idempotencyKey);

        return DB::transaction(function () use ($actor, $depositId, $folioId, $amount, $idempotencyKey, $propertyId) {
            $deposit = GuestDepositTransaction::whereKey($depositId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $folio = Folio::withoutGlobalScope('property')->whereKey($folioId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $this->assertApplicationSources($deposit, $folio);
            $existing = GuestDepositApplication::where('property_id', $propertyId)->where('application_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->guest_deposit_transaction_id !== $deposit->id || $existing->folio_id !== $folio->id
                    || $this->amount($existing->amount) !== $amount || $existing->applied_by !== $actor->id) throw new DomainException('GUEST_DEPOSIT_APPLICATION_IDEMPOTENCY_CONFLICT');
                return $existing->fresh();
            }
            if (bccomp(bcadd($this->resolvedAmount($deposit), $amount, 2), $this->amount($deposit->amount), 2) > 0) throw new DomainException('GUEST_DEPOSIT_OVER_APPLICATION');
            $application = new GuestDepositApplication();
            $application->forceFill([
                'property_id' => $propertyId, 'guest_deposit_transaction_id' => $deposit->id, 'folio_id' => $folio->id,
                'amount' => $amount, 'application_idempotency_key' => $idempotencyKey, 'applied_at' => now(), 'applied_by' => $actor->id,
                'source_snapshot' => ['deposit_id' => $deposit->id, 'deposit_number' => $deposit->deposit_number,
                    'folio_id' => $folio->id, 'folio_number' => $folio->folio_number, 'reservation_id' => $deposit->reservation_id,
                    'guest_id' => $deposit->guest_id, 'currency' => $deposit->currency, 'amount' => $amount], 'created_at' => now(),
            ])->save();
            $this->effects->applyAcceptedDepositApplication($application, $deposit, $folio);
            $this->refreshStatus($deposit, $actor->id);
            DB::afterCommit(fn () => event(new GuestDepositApplied($application->fresh())));
            return $application->fresh();
        });
    }

    public function voidDeposit(User $actor, string $depositId, string $reasonCode, string $idempotencyKey): GuestDepositReversal
    {
        $propertyId = $this->propertyId();
        $actor = $this->actor($actor, self::VOID_PERMISSION, $propertyId);
        $reasonCode = $this->reason($reasonCode); $idempotencyKey = $this->idempotency($idempotencyKey);
        $this->confirmation->requireValidConfirmation($actor, self::VOID_INTENT, session('active_company_id'), $propertyId);
        return DB::transaction(function () use ($actor, $depositId, $reasonCode, $idempotencyKey, $propertyId) {
            $deposit = GuestDepositTransaction::whereKey($depositId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $existing = GuestDepositReversal::where('property_id', $propertyId)->where('reversal_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->guest_deposit_transaction_id !== $deposit->id || $existing->reversal_type !== GuestDepositReversalTypeEnum::DepositVoid
                    || $existing->reason_code !== $reasonCode || $existing->reversed_by !== $actor->id) throw new DomainException('GUEST_DEPOSIT_VOID_IDEMPOTENCY_CONFLICT');
                return $existing->fresh();
            }
            if ($deposit->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided) throw new DomainException('Guest deposit is already voided.');
            if (GuestDepositApplication::where('property_id', $propertyId)->where('guest_deposit_transaction_id', $deposit->id)->exists()
                || GuestRefundTransaction::where('property_id', $propertyId)->where('guest_deposit_transaction_id', $deposit->id)->exists()) {
                throw new DomainException('Guest deposit with application or refund history cannot be voided.');
            }
            $reversal = $this->createReversal($deposit, null, GuestDepositReversalTypeEnum::DepositVoid, $this->amount($deposit->amount), $reasonCode, $idempotencyKey, $actor);
            $deposit->forceFill(['lifecycle_status' => GuestDepositLifecycleStatusEnum::Voided, 'updated_by' => $actor->id])->save();
            DB::afterCommit(fn () => event(new GuestDepositVoided($reversal->fresh())));
            return $reversal->fresh();
        });
    }

    public function reverseDepositApplication(User $actor, string $applicationId, string $reasonCode, string $idempotencyKey): GuestDepositReversal
    {
        $propertyId = $this->propertyId();
        $actor = $this->actor($actor, self::REVERSE_PERMISSION, $propertyId);
        $reasonCode = $this->reason($reasonCode); $idempotencyKey = $this->idempotency($idempotencyKey);
        $this->confirmation->requireValidConfirmation($actor, self::REVERSE_INTENT, session('active_company_id'), $propertyId);
        return DB::transaction(function () use ($actor, $applicationId, $reasonCode, $idempotencyKey, $propertyId) {
            $identity = GuestDepositApplication::whereKey($applicationId)->where('property_id', $propertyId)->firstOrFail();
            $deposit = GuestDepositTransaction::whereKey($identity->guest_deposit_transaction_id)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $folio = Folio::withoutGlobalScope('property')->whereKey($identity->folio_id)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $application = GuestDepositApplication::whereKey($applicationId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $existing = GuestDepositReversal::where('property_id', $propertyId)->where('reversal_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->guest_deposit_application_id !== $application->id || $existing->reason_code !== $reasonCode || $existing->reversed_by !== $actor->id) throw new DomainException('GUEST_DEPOSIT_REVERSAL_IDEMPOTENCY_CONFLICT');
                return $existing->fresh();
            }
            if (GuestDepositReversal::where('property_id', $propertyId)->where('guest_deposit_application_id', $application->id)->where('reversal_type', GuestDepositReversalTypeEnum::ApplicationReversal->value)->exists()) throw new DomainException('Guest deposit application has already been reversed.');
            $reversal = $this->createReversal($deposit, $application, GuestDepositReversalTypeEnum::ApplicationReversal, $this->amount($application->amount), $reasonCode, $idempotencyKey, $actor);
            $this->effects->applyAcceptedDepositApplicationReversal($reversal, $application, $deposit, $folio);
            $this->refreshStatus($deposit, $actor->id);
            DB::afterCommit(fn () => event(new GuestDepositApplicationReversed($reversal->fresh())));
            return $reversal->fresh();
        });
    }

    private function createReversal(GuestDepositTransaction $deposit, ?GuestDepositApplication $application, GuestDepositReversalTypeEnum $type, string $amount, string $reason, string $key, User $actor): GuestDepositReversal
    {
        $row = new GuestDepositReversal();
        $row->forceFill(['property_id' => $deposit->property_id, 'guest_deposit_transaction_id' => $deposit->id,
            'guest_deposit_application_id' => $application?->id, 'reversal_type' => $type, 'amount' => $amount,
            'reason_code' => $reason, 'reversal_idempotency_key' => $key, 'reversed_at' => now(), 'reversed_by' => $actor->id,
            'source_snapshot' => ['deposit_id' => $deposit->id, 'application_id' => $application?->id, 'amount' => $amount, 'reason_code' => $reason],
            'created_at' => now()])->save();
        return $row;
    }
    private function assertApplicationSources(GuestDepositTransaction $deposit, Folio $folio): void
    {
        if ($deposit->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided || $folio->status !== FolioStatusEnum::Open
            || $deposit->property_id !== $folio->property_id || $deposit->reservation_id !== $folio->reservation_id
            || $deposit->guest_id !== $folio->guest_id || $deposit->currency !== $folio->currency) throw new DomainException('Deposit and Folio source evidence do not match.');
    }
    private function resolvedAmount(GuestDepositTransaction $deposit): string
    {
        $total = '0.00';
        foreach (GuestDepositApplication::where('property_id', $deposit->property_id)->where('guest_deposit_transaction_id', $deposit->id)->get() as $app) {
            if (!GuestDepositReversal::where('property_id', $deposit->property_id)->where('guest_deposit_application_id', $app->id)->where('reversal_type', 'APPLICATION_REVERSAL')->exists()) $total = bcadd($total, $this->amount($app->amount), 2);
        }
        foreach (GuestRefundTransaction::where('property_id', $deposit->property_id)->where('guest_deposit_transaction_id', $deposit->id)->get() as $refund) $total = bcadd($total, $this->amount($refund->amount), 2);
        return $total;
    }
    public function refreshStatus(GuestDepositTransaction $deposit, string $actorId): void
    {
        if ($deposit->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided) return;
        $resolved = $this->resolvedAmount($deposit); $amount = $this->amount($deposit->amount);
        $status = bccomp($resolved, '0.00', 2) === 0 ? GuestDepositLifecycleStatusEnum::Recorded
            : (bccomp($resolved, $amount, 2) < 0 ? GuestDepositLifecycleStatusEnum::PartiallyResolved : GuestDepositLifecycleStatusEnum::Resolved);
        $deposit->forceFill(['lifecycle_status' => $status, 'updated_by' => $actorId])->save();
    }
    private function assertSession(CashierSession $session, User $actor): void
    {
        if ($session->status !== CashierSessionStatusEnum::OPEN) throw new DomainException('Cashier Session must be OPEN for guest cash deposit.');
        if ($session->cashier_user_id !== $actor->id) throw new AuthorizationException('Guest cash deposit requires the active cashier session owner.');
    }
    private function actor(User $actor, string $permission, string $propertyId): User
    {
        if (!auth()->check() || auth()->id() !== $actor->id) throw new AuthorizationException('Guest deposit actor must match the authenticated session.');
        $fresh = User::whereKey($actor->id)->where('is_active', true)->first();
        if (!$fresh || !$fresh->properties()->where('properties.id', $propertyId)->wherePivot('status', 'active')->exists()) throw new AuthorizationException('Guest deposit requires active property access.');
        try { $allowed = $fresh->can($permission); } catch (Throwable) { $allowed = false; }
        if (!$allowed) throw new AuthorizationException('Guest deposit permission is required.');
        return $fresh;
    }
    private function propertyId(): string { $id = session('active_property_id') ?? session('current_property_id') ?? $this->currentProperty->resolveOrFail(); $this->currentProperty->setPropertyId($id); return $id; }
    private function idempotency(string $value): string { $value = trim($value); if ($value === '' || mb_strlen($value) > 96) throw ValidationException::withMessages(['idempotency_key' => ['A valid idempotency key is required.']]); return $value; }
    private function reason(string $value): string { $value = trim($value); if ($value === '' || mb_strlen($value) > 80) throw ValidationException::withMessages(['reason_code' => ['A valid reason code is required.']]); return $value; }
    private function positiveAmount(string $value): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) throw ValidationException::withMessages(['amount' => ['Amount must be a plain positive decimal.']]);
        [$i,$f] = array_pad(explode('.', $value, 2),2,''); if (strlen($i)>10 || (strlen($f)>2 && rtrim(substr($f,2),'0')!=='')) throw ValidationException::withMessages(['amount'=>['Amount exceeds decimal(12,2).']]);
        $v=bcadd($i.'.'.str_pad(substr($f,0,2),2,'0'),'0.00',2); if(bccomp($v,'0.00',2)<=0) throw ValidationException::withMessages(['amount'=>['Amount must be positive.']]); return $v;
    }
    private function amount(mixed $value): string { return bcadd((string)$value,'0.00',2); }
}

<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Enums\GuestRefundSourceTypeEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Events\GuestCashRefundRecorded;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GuestRefundLifecycleService
{
    public const RECORD_PERMISSION = 'pms.cashiering.guest-refund.record';
    public const CONFIRMATION_INTENT = 'guest-cash-refund';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
        private readonly SensitiveActionConfirmationService $confirmation,
        private readonly GuestDepositLifecycleService $deposits,
    ) {}

    public function recordCashRefund(User $actor, string $sourceType, string $sourceId, string $cashierSessionId, string $amount, string $reasonCode, string $idempotencyKey): GuestRefundTransaction
    {
        $propertyId = $this->propertyId();
        $actor = $this->actor($actor, $propertyId);
        $type = GuestRefundSourceTypeEnum::tryFrom($sourceType) ?? throw ValidationException::withMessages(['source_type' => ['A valid refund source type is required.']]);
        $amount = $this->positiveAmount($amount); $reasonCode = $this->reason($reasonCode); $idempotencyKey = $this->idempotency($idempotencyKey);
        $this->confirmation->requireValidConfirmation($actor, self::CONFIRMATION_INTENT, session('active_company_id'), $propertyId);

        return DB::transaction(function () use ($actor, $type, $sourceId, $cashierSessionId, $amount, $reasonCode, $idempotencyKey, $propertyId) {
            $source = $type === GuestRefundSourceTypeEnum::GuestPayment
                ? GuestPaymentTransaction::whereKey($sourceId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail()
                : GuestDepositTransaction::whereKey($sourceId)->where('property_id', $propertyId)->lockForUpdate()->firstOrFail();

            $session = CashierSession::whereKey($cashierSessionId)->where('property_id', $propertyId)->sharedLock()->firstOrFail();
            if ($session->status !== CashierSessionStatusEnum::OPEN) throw new DomainException('Cashier Session must be OPEN for guest cash refund.');
            if ($session->cashier_user_id !== $actor->id) throw new AuthorizationException('Guest cash refund requires the active cashier session owner.');

            $existing = GuestRefundTransaction::where('property_id', $propertyId)->where('refund_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                $matchesSource = $type === GuestRefundSourceTypeEnum::GuestPayment
                    ? $existing->guest_payment_transaction_id === $source->id && $existing->guest_deposit_transaction_id === null
                    : $existing->guest_deposit_transaction_id === $source->id && $existing->guest_payment_transaction_id === null;
                if (!$matchesSource || $existing->cashier_session_id !== $session->id || $this->amount($existing->amount) !== $amount
                    || $existing->reason_code !== $reasonCode || $existing->refunded_by !== $actor->id) throw new DomainException('GUEST_REFUND_IDEMPOTENCY_CONFLICT');
                return $existing->fresh();
            }

            $available = $type === GuestRefundSourceTypeEnum::GuestPayment ? $this->paymentAvailable($source) : $this->depositAvailable($source);
            if (bccomp($amount, $available, 2) > 0) throw new DomainException('GUEST_REFUND_EXCEEDS_AVAILABLE_SOURCE');
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['glf-c-refund-number-' . $propertyId]);

            $refund = new GuestRefundTransaction();
            $refund->forceFill([
                'property_id' => $propertyId, 'refund_number' => sprintf('GRF-%05d', GuestRefundTransaction::where('property_id', $propertyId)->count() + 1),
                'reservation_id' => $source->reservation_id, 'guest_id' => $source->guest_id, 'currency' => $source->currency,
                'amount' => $amount, 'tender_type' => GuestPaymentTenderTypeEnum::Cash, 'cashier_session_id' => $session->id,
                'refund_source_type' => $type, 'guest_payment_transaction_id' => $type === GuestRefundSourceTypeEnum::GuestPayment ? $source->id : null,
                'guest_deposit_transaction_id' => $type === GuestRefundSourceTypeEnum::GuestDeposit ? $source->id : null,
                'reason_code' => $reasonCode, 'refund_idempotency_key' => $idempotencyKey, 'refunded_at' => now(),
                'refunded_by' => $actor->id, 'source_snapshot' => ['source_type' => $type->value, 'source_id' => $source->id,
                    'source_number' => $type === GuestRefundSourceTypeEnum::GuestPayment ? $source->payment_number : $source->deposit_number,
                    'source_amount' => $this->amount($source->amount), 'available_before_refund' => $available,
                    'cashier_session_id' => $session->id, 'cashier_user_id' => $session->cashier_user_id,
                    'reservation_id' => $source->reservation_id, 'guest_id' => $source->guest_id, 'currency' => $source->currency,
                    'amount' => $amount, 'reason_code' => $reasonCode], 'created_at' => now(), 'created_by' => $actor->id,
            ])->save();
            if ($source instanceof GuestDepositTransaction) $this->deposits->refreshStatus($source, $actor->id);
            DB::afterCommit(fn () => event(new GuestCashRefundRecorded($refund->fresh())));
            return $refund->fresh();
        });
    }

    private function paymentAvailable(GuestPaymentTransaction $payment): string
    {
        if ($payment->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided) {
            throw new DomainException('Voided guest payments are not refundable.');
        }
        $committed = '0.00';
        foreach (GuestPaymentAllocation::where('property_id', $payment->property_id)->where('guest_payment_transaction_id', $payment->id)->get() as $allocation) {
            if (!GuestPaymentReversal::where('property_id', $payment->property_id)->where('guest_payment_allocation_id', $allocation->id)->where('reversal_type', GuestPaymentReversalTypeEnum::AllocationReversal->value)->exists()) $committed = bcadd($committed, $this->amount($allocation->amount), 2);
        }
        $refunded = $this->refundTotal('guest_payment_transaction_id', $payment->id, $payment->property_id);
        return bcsub(bcsub($this->amount($payment->amount), $committed, 2), $refunded, 2);
    }
    private function depositAvailable(GuestDepositTransaction $deposit): string
    {
        if ($deposit->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided) {
            throw new DomainException('Voided guest deposits are not refundable.');
        }
        $committed = '0.00';
        foreach (GuestDepositApplication::where('property_id', $deposit->property_id)->where('guest_deposit_transaction_id', $deposit->id)->get() as $application) {
            if (!GuestDepositReversal::where('property_id', $deposit->property_id)->where('guest_deposit_application_id', $application->id)->where('reversal_type', GuestDepositReversalTypeEnum::ApplicationReversal->value)->exists()) $committed = bcadd($committed, $this->amount($application->amount), 2);
        }
        return bcsub(bcsub($this->amount($deposit->amount), $committed, 2), $this->refundTotal('guest_deposit_transaction_id', $deposit->id, $deposit->property_id), 2);
    }
    private function refundTotal(string $column, string $sourceId, string $propertyId): string
    {
        $total='0.00'; foreach(GuestRefundTransaction::where('property_id',$propertyId)->where($column,$sourceId)->get() as $refund) $total=bcadd($total,$this->amount($refund->amount),2); return $total;
    }
    private function actor(User $actor, string $propertyId): User
    {
        if (!auth()->check() || auth()->id() !== $actor->id) throw new AuthorizationException('Guest refund actor must match the authenticated session.');
        $fresh=User::whereKey($actor->id)->where('is_active',true)->first();
        if(!$fresh || !$fresh->properties()->where('properties.id',$propertyId)->wherePivot('status','active')->exists()) throw new AuthorizationException('Guest refund requires active property access.');
        try{$allowed=$fresh->can(self::RECORD_PERMISSION);}catch(Throwable){$allowed=false;} if(!$allowed) throw new AuthorizationException('Guest refund permission is required.'); return $fresh;
    }
    private function propertyId(): string { $id=session('active_property_id')??session('current_property_id')??$this->currentProperty->resolveOrFail(); $this->currentProperty->setPropertyId($id); return $id; }
    private function idempotency(string $v): string {$v=trim($v);if($v===''||mb_strlen($v)>96)throw ValidationException::withMessages(['idempotency_key'=>['A valid idempotency key is required.']]);return $v;}
    private function reason(string $v): string {$v=trim($v);if($v===''||mb_strlen($v)>80)throw ValidationException::withMessages(['reason_code'=>['A valid reason code is required.']]);return $v;}
    private function positiveAmount(string $v): string {if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/',$v))throw ValidationException::withMessages(['amount'=>['Amount must be a plain positive decimal.']]);[$i,$f]=array_pad(explode('.',$v,2),2,'');if(strlen($i)>10||(strlen($f)>2&&rtrim(substr($f,2),'0')!==''))throw ValidationException::withMessages(['amount'=>['Amount exceeds decimal(12,2).']]);$n=bcadd($i.'.'.str_pad(substr($f,0,2),2,'0'),'0.00',2);if(bccomp($n,'0.00',2)<=0)throw ValidationException::withMessages(['amount'=>['Amount must be positive.']]);return $n;}
    private function amount(mixed $v): string {return bcadd((string)$v,'0.00',2);}
}

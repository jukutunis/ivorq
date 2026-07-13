<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use BackedEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutObligationStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Services\Ports\GeneralCashierCheckoutObligationSnapshotProbe;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestRefundSourceTypeEnum;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutObligationProjection;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GeneralCashierCheckoutObligationProjectionService
{
    public const VIEW_PERMISSION = 'finance.general-cashier.checkout-obligation.view';
    public const PROJECTION_VERSION = 'GC-A1-1.0';
    public const STABLE_ERROR_NESTED_TX = 'GC_A1_REQUIRES_TOP_LEVEL_READ_TRANSACTION';

    private const AUTHORIZATION_FAILURE_MESSAGE = 'General Cashier checkout obligation view is not authorized.';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    public function project(User $actor, string $frontDeskStayId): GeneralCashierCheckoutObligationProjection
    {
        $propertyId = $this->authorizeView($actor);

        if (DB::transactionLevel() > 0) {
            throw new DomainException(self::STABLE_ERROR_NESTED_TX);
        }

        return DB::transaction(function () use ($actor, $frontDeskStayId, $propertyId) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->evaluateProjection($actor, $frontDeskStayId, $propertyId);
        });
    }

    private function evaluateProjection(User $actor, string $frontDeskStayId, string $propertyId): GeneralCashierCheckoutObligationProjection
    {
        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($frontDeskStayId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $stay) {
            throw new NotFoundException('FrontDeskStay');
        }

        $evaluatedAt = now()->toIsoString();
        $blockers = [];
        $messages = [];
        $reviews = [];
        $unavailable = [];
        $markers = [
            'projection_owner' => 'GENERAL_CASHIER',
            'read_model' => 'READ_ONLY',
        ];

        $reservation = Reservation::withoutGlobalScopes()
            ->whereKey($stay->reservation_id)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation) {
            return $this->evidenceUnavailable(
                $propertyId,
                $frontDeskStayId,
                '',
                (string) ($stay->guest_id ?? ''),
                [],
                [],
                $markers + ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                ['front_desk_stay_id' => $frontDeskStayId],
                'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE',
                'Stay-to-Reservation relationship could not be resolved.'
            );
        }

        $guestId = (string) ($reservation->primary_guest_id ?? '');
        $guest = $guestId === '' ? null : Guest::withoutGlobalScopes()
            ->whereKey($guestId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $guest || $stay->guest_id !== $guestId) {
            return $this->evidenceUnavailable(
                $propertyId,
                $frontDeskStayId,
                $reservation->id,
                $guestId,
                [],
                [],
                $markers + ['stay_relationship_marker' => 'STAY_RESERVATION_GUEST_LINK_EVIDENCE_UNAVAILABLE'],
                ['front_desk_stay_id' => $frontDeskStayId, 'reservation_id' => $reservation->id],
                'STAY_RESERVATION_GUEST_LINK_EVIDENCE_UNAVAILABLE',
                'Stay, Reservation, and Guest relationship could not be resolved.'
            );
        }

        $markers['stay_relationship_marker'] = 'STAY_RESERVATION_GUEST_RESOLVED';

        $payments = GuestPaymentTransaction::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id)
            ->where('guest_id', $guestId)
            ->orderBy('id')
            ->get();

        $deposits = GuestDepositTransaction::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id)
            ->where('guest_id', $guestId)
            ->orderBy('id')
            ->get();

        $refunds = GuestRefundTransaction::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id)
            ->where('guest_id', $guestId)
            ->orderBy('id')
            ->get();

        $relevance = $this->evaluateLifecycleRelevance(
            $payments,
            $deposits,
            $refunds,
            $propertyId,
            $reservation->id,
            $guestId,
            $reviews,
            $unavailable,
            $messages
        );

        $relevantPayments = new Collection($relevance['payments']);
        $relevantDeposits = new Collection($relevance['deposits']);
        $relevantRefunds = new Collection($relevance['refunds']);

        $sessionIds = $this->collectSessionIds($relevantPayments, $relevantDeposits, $relevantRefunds, $unavailable, $messages);
        $this->signalSnapshotAfterCashSourceRead($propertyId, $frontDeskStayId);

        $sessions = empty($sessionIds)
            ? collect()
            : CashierSession::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->whereIn('id', $sessionIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

        $this->evaluateLinkedSessions($sessionIds, $sessions, $blockers, $messages, $unavailable);
        $this->evaluateSourceSnapshots($relevantPayments, $sessions, 'guest_payment', $reviews, $messages);
        $this->evaluateSourceSnapshots($relevantDeposits, $sessions, 'guest_deposit', $reviews, $messages);
        $this->evaluateSourceSnapshots($relevantRefunds, $sessions, 'guest_refund', $reviews, $messages);

        if (! empty($sessionIds) && empty($blockers) && empty($reviews)) {
            $unavailable[] = 'CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE';
            $messages[] = 'Linked cashier sessions exist, but the current General Cashier schema has no session-scoped handover, count, close-reconciliation, or accountability-completion evidence.';
        }

        $markers['cashier_obligation_scope_marker'] = empty($sessionIds)
            ? 'NO_AUTHORITATIVE_CASHIER_OBLIGATIONS'
            : 'AUTHORITATIVE_CASHIER_OBLIGATIONS_FOUND';
        $markers['cashier_accountability_marker'] = $this->accountabilityMarker($blockers, $reviews, $unavailable);

        $paymentIds = $relevantPayments->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $status = $this->determineStatus($reviews, $unavailable, $blockers);

        $sourceIdentifiers = [
            'property_id' => $propertyId,
            'front_desk_stay_id' => $frontDeskStayId,
            'reservation_id' => $reservation->id,
            'guest_id' => $guestId,
            'related_guest_payment_transaction_ids' => $paymentIds,
            'related_guest_deposit_transaction_ids' => $relevantDeposits->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'related_guest_refund_transaction_ids' => $relevantRefunds->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'related_cashier_session_ids' => $sessionIds,
            'lifecycle_relevance_matrix' => $relevance['matrix'],
        ];

        $fingerprint = $this->buildFingerprint(
            $propertyId,
            $frontDeskStayId,
            $reservation->id,
            $guestId,
            $status->value,
            $payments,
            $deposits,
            $refunds,
            $relevance['facts'],
            $sessions->all(),
            $blockers,
            $reviews,
            $unavailable
        );

        return GeneralCashierCheckoutObligationProjection::create(
            projection_version: self::PROJECTION_VERSION,
            status: $status,
            property_id: $propertyId,
            front_desk_stay_id: $frontDeskStayId,
            reservation_id: $reservation->id,
            guest_id: $guestId,
            related_guest_payment_transaction_ids: $paymentIds,
            related_cashier_session_ids: $sessionIds,
            blocker_codes: $blockers,
            blocker_messages: $messages,
            review_reasons: $reviews,
            evidence_unavailable_codes: $unavailable,
            markers: $markers,
            evaluated_at: $evaluatedAt,
            source_fingerprint: $fingerprint,
            source_identifiers: $sourceIdentifiers,
        );
    }

    private function authorizeView(User $actor): string
    {
        if (! auth()->check() || auth()->id() !== $actor->id) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        $fresh = User::whereKey($actor->id)
            ->where('is_active', true)
            ->first();

        if (! $fresh) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        $propertyId = $this->currentProperty->resolveOrFail();
        $this->currentProperty->setPropertyId($propertyId);
        $companyId = session('active_company_id');

        if (! is_string($companyId) || trim($companyId) === '') {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        $company = Company::withoutGlobalScopes()
            ->whereKey($companyId)
            ->where('is_active', true)
            ->first();

        if (! $company) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->where('company_id', $company->id)
            ->first();

        if (! $property) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        $hasMembership = $fresh->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasMembership) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        try {
            $allowed = $fresh->can(self::VIEW_PERMISSION);
        } catch (Throwable) {
            $allowed = false;
        }

        if (! $allowed) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        return $propertyId;
    }

    private function evaluateLifecycleRelevance(
        Collection $payments,
        Collection $deposits,
        Collection $refunds,
        string $propertyId,
        string $reservationId,
        string $guestId,
        array &$reviews,
        array &$unavailable,
        array &$messages
    ): array {
        $relevantPayments = [];
        $relevantDeposits = [];
        $relevantRefunds = [];
        $facts = [
            'payments' => [],
            'deposits' => [],
            'refunds' => [],
        ];
        $matrix = [
            'guest_payments' => 'RECORDED and PARTIALLY_ALLOCATED remain relevant while unallocated/unrefunded amount remains; FULLY_ALLOCATED, fully refunded, and source-proven VOIDED rows are excluded; allocation reversals reduce resolved amount.',
            'guest_deposits' => 'RECORDED and PARTIALLY_RESOLVED remain relevant while unapplied/unrefunded amount remains; RESOLVED and source-proven VOIDED rows are excluded; application reversals reduce resolved amount.',
            'guest_refunds' => 'Payment-sourced and deposit-sourced cash refunds are terminal cashier cash-out events and remain relevant when their source evidence is valid.',
        ];

        foreach ($payments as $payment) {
            $decision = $this->evaluatePaymentRelevance($payment, $propertyId, $reviews, $messages);
            $facts['payments'][] = $decision['fact'];
            if ($decision['relevant']) {
                $relevantPayments[] = $payment;
            }
        }

        foreach ($deposits as $deposit) {
            $decision = $this->evaluateDepositRelevance($deposit, $propertyId, $reviews, $messages);
            $facts['deposits'][] = $decision['fact'];
            if ($decision['relevant']) {
                $relevantDeposits[] = $deposit;
            }
        }

        foreach ($refunds as $refund) {
            $decision = $this->evaluateRefundRelevance($refund, $propertyId, $reservationId, $guestId, $reviews, $messages);
            $facts['refunds'][] = $decision['fact'];
            if ($decision['relevant']) {
                $relevantRefunds[] = $refund;
            }
        }

        return [
            'payments' => $relevantPayments,
            'deposits' => $relevantDeposits,
            'refunds' => $relevantRefunds,
            'facts' => $facts,
            'matrix' => $matrix,
        ];
    }

    private function evaluatePaymentRelevance(GuestPaymentTransaction $payment, string $propertyId, array &$reviews, array &$messages): array
    {
        $amount = $this->amount($payment->amount);
        $allocations = GuestPaymentAllocation::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('guest_payment_transaction_id', $payment->id)
            ->orderBy('id')
            ->get();
        $refunds = GuestRefundTransaction::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('guest_payment_transaction_id', $payment->id)
            ->orderBy('id')
            ->get();
        $reversals = GuestPaymentReversal::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('guest_payment_transaction_id', $payment->id)
            ->orderBy('id')
            ->get();
        $voids = $reversals->filter(fn ($row): bool => $row->reversal_type === GuestPaymentReversalTypeEnum::PaymentVoid);
        $activeAllocated = '0.00';
        $allocationFacts = [];

        foreach ($allocations as $allocation) {
            $allocationReversals = $reversals
                ->filter(fn ($row): bool => $row->reversal_type === GuestPaymentReversalTypeEnum::AllocationReversal
                    && $row->guest_payment_allocation_id === $allocation->id)
                ->values();

            if ($allocationReversals->count() > 1) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Payment allocation {$allocation->id} has multiple allocation reversals.";
            }

            $reversed = $allocationReversals->count() >= 1;
            if (! $reversed) {
                $activeAllocated = bcadd($activeAllocated, $this->amount($allocation->amount), 2);
            }

            $allocationFacts[] = [
                'id' => (string) $allocation->id,
                'amount' => $this->amount($allocation->amount),
                'folio_id' => (string) $allocation->folio_id,
                'reversed' => $reversed,
                'reversal_ids' => $allocationReversals->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            ];
        }

        $refunded = $this->sumAmounts($refunds);
        $resolved = bcadd($activeAllocated, $refunded, 2);
        $remaining = bcsub($amount, $resolved, 2);
        $status = $payment->lifecycle_status;
        $relevant = false;
        $reason = 'PAYMENT_EXCLUDED';

        if ($status === GuestPaymentLifecycleStatusEnum::Voided) {
            if ($voids->count() !== 1 || $allocations->isNotEmpty() || $refunds->isNotEmpty()) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Voided payment {$payment->id} does not have source-consistent void evidence.";
            }
            $reason = 'PAYMENT_VOIDED';
        } else {
            if ($voids->isNotEmpty()) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Payment {$payment->id} has void evidence but is not VOIDED.";
            }

            $expected = match (true) {
                bccomp($activeAllocated, '0.00', 2) === 0 => GuestPaymentLifecycleStatusEnum::Recorded,
                bccomp($activeAllocated, $amount, 2) < 0 => GuestPaymentLifecycleStatusEnum::PartiallyAllocated,
                bccomp($activeAllocated, $amount, 2) === 0 => GuestPaymentLifecycleStatusEnum::FullyAllocated,
                default => null,
            };

            if ($expected === null || bccomp($resolved, $amount, 2) > 0) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Payment {$payment->id} is over-resolved.";
            } elseif ($status !== $expected) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Payment {$payment->id} lifecycle {$status->value} does not match source-proven {$expected->value}.";
            }

            $relevant = bccomp($remaining, '0.00', 2) > 0;
            $reason = $relevant ? 'PAYMENT_UNRESOLVED_CASH_RECEIPT' : 'PAYMENT_FULLY_RESOLVED';
        }

        return [
            'relevant' => $relevant,
            'fact' => [
                'id' => (string) $payment->id,
                'status' => $status?->value ?? (string) $status,
                'amount' => $amount,
                'active_allocated' => $activeAllocated,
                'refunded' => $refunded,
                'remaining' => $remaining,
                'relevance' => $reason,
                'cashier_session_id' => (string) $payment->cashier_session_id,
                'allocations' => $allocationFacts,
                'reversals' => $this->reversalFacts($reversals),
                'refund_ids' => $refunds->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            ],
        ];
    }

    private function evaluateDepositRelevance(GuestDepositTransaction $deposit, string $propertyId, array &$reviews, array &$messages): array
    {
        $amount = $this->amount($deposit->amount);
        $applications = GuestDepositApplication::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('guest_deposit_transaction_id', $deposit->id)
            ->orderBy('id')
            ->get();
        $refunds = GuestRefundTransaction::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('guest_deposit_transaction_id', $deposit->id)
            ->orderBy('id')
            ->get();
        $reversals = GuestDepositReversal::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('guest_deposit_transaction_id', $deposit->id)
            ->orderBy('id')
            ->get();
        $voids = $reversals->filter(fn ($row): bool => $row->reversal_type === GuestDepositReversalTypeEnum::DepositVoid);
        $activeApplied = '0.00';
        $applicationFacts = [];

        foreach ($applications as $application) {
            $applicationReversals = $reversals
                ->filter(fn ($row): bool => $row->reversal_type === GuestDepositReversalTypeEnum::ApplicationReversal
                    && $row->guest_deposit_application_id === $application->id)
                ->values();

            if ($applicationReversals->count() > 1) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Deposit application {$application->id} has multiple application reversals.";
            }

            $reversed = $applicationReversals->count() >= 1;
            if (! $reversed) {
                $activeApplied = bcadd($activeApplied, $this->amount($application->amount), 2);
            }

            $applicationFacts[] = [
                'id' => (string) $application->id,
                'amount' => $this->amount($application->amount),
                'folio_id' => (string) $application->folio_id,
                'reversed' => $reversed,
                'reversal_ids' => $applicationReversals->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            ];
        }

        $refunded = $this->sumAmounts($refunds);
        $resolved = bcadd($activeApplied, $refunded, 2);
        $remaining = bcsub($amount, $resolved, 2);
        $status = $deposit->lifecycle_status;
        $relevant = false;
        $reason = 'DEPOSIT_EXCLUDED';

        if ($status === GuestDepositLifecycleStatusEnum::Voided) {
            if ($voids->count() !== 1 || $applications->isNotEmpty() || $refunds->isNotEmpty()) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Voided deposit {$deposit->id} does not have source-consistent void evidence.";
            }
            $reason = 'DEPOSIT_VOIDED';
        } else {
            if ($voids->isNotEmpty()) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Deposit {$deposit->id} has void evidence but is not VOIDED.";
            }

            $expected = match (true) {
                bccomp($resolved, '0.00', 2) === 0 => GuestDepositLifecycleStatusEnum::Recorded,
                bccomp($resolved, $amount, 2) < 0 => GuestDepositLifecycleStatusEnum::PartiallyResolved,
                bccomp($resolved, $amount, 2) === 0 => GuestDepositLifecycleStatusEnum::Resolved,
                default => null,
            };

            if ($expected === null || bccomp($resolved, $amount, 2) > 0) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Deposit {$deposit->id} is over-resolved.";
            } elseif ($status !== $expected) {
                $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
                $messages[] = "Deposit {$deposit->id} lifecycle {$status->value} does not match source-proven {$expected->value}.";
            }

            $relevant = bccomp($remaining, '0.00', 2) > 0;
            $reason = $relevant ? 'DEPOSIT_UNRESOLVED_CASH_RECEIPT' : 'DEPOSIT_FULLY_RESOLVED';
        }

        return [
            'relevant' => $relevant,
            'fact' => [
                'id' => (string) $deposit->id,
                'status' => $status?->value ?? (string) $status,
                'amount' => $amount,
                'active_applied' => $activeApplied,
                'refunded' => $refunded,
                'remaining' => $remaining,
                'relevance' => $reason,
                'cashier_session_id' => (string) $deposit->cashier_session_id,
                'applications' => $applicationFacts,
                'reversals' => $this->reversalFacts($reversals),
                'refund_ids' => $refunds->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            ],
        ];
    }

    private function evaluateRefundRelevance(
        GuestRefundTransaction $refund,
        string $propertyId,
        string $reservationId,
        string $guestId,
        array &$reviews,
        array &$messages
    ): array {
        $sourceType = $refund->refund_source_type?->value ?? (string) $refund->refund_source_type;
        $hasPayment = ! empty($refund->guest_payment_transaction_id);
        $hasDeposit = ! empty($refund->guest_deposit_transaction_id);
        $sourceValid = true;

        if (($sourceType === GuestRefundSourceTypeEnum::GuestPayment->value && ! $hasPayment)
            || ($sourceType === GuestRefundSourceTypeEnum::GuestDeposit->value && ! $hasDeposit)
            || ($hasPayment && $hasDeposit)
            || (! $hasPayment && ! $hasDeposit)) {
            $sourceValid = false;
        }

        $source = null;
        if ($hasPayment && ! $hasDeposit) {
            $source = GuestPaymentTransaction::withoutGlobalScopes()
                ->whereKey($refund->guest_payment_transaction_id)
                ->where('property_id', $propertyId)
                ->first();
        } elseif ($hasDeposit && ! $hasPayment) {
            $source = GuestDepositTransaction::withoutGlobalScopes()
                ->whereKey($refund->guest_deposit_transaction_id)
                ->where('property_id', $propertyId)
                ->first();
        }

        if (! $source
            || $source->reservation_id !== $reservationId
            || $source->guest_id !== $guestId
            || $source->currency !== $refund->currency) {
            $sourceValid = false;
        }

        if (! $sourceValid) {
            $reviews[] = 'CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT';
            $messages[] = "Refund {$refund->id} source evidence is inconsistent.";
        }

        return [
            'relevant' => true,
            'fact' => [
                'id' => (string) $refund->id,
                'source_type' => $sourceType,
                'amount' => $this->amount($refund->amount),
                'cashier_session_id' => (string) $refund->cashier_session_id,
                'guest_payment_transaction_id' => (string) ($refund->guest_payment_transaction_id ?? ''),
                'guest_deposit_transaction_id' => (string) ($refund->guest_deposit_transaction_id ?? ''),
                'source_valid' => $sourceValid,
                'relevance' => 'REFUND_CASH_OUT_EVENT',
            ],
        ];
    }

    private function signalSnapshotAfterCashSourceRead(string $propertyId, string $frontDeskStayId): void
    {
        if (! app()->bound(GeneralCashierCheckoutObligationSnapshotProbe::class)) {
            return;
        }

        app(GeneralCashierCheckoutObligationSnapshotProbe::class)->afterCashSourceRead($propertyId, $frontDeskStayId);
    }

    private function collectSessionIds(
        Collection $payments,
        Collection $deposits,
        Collection $refunds,
        array &$unavailable,
        array &$messages
    ): array {
        $ids = [];

        foreach ([['guest payment', $payments], ['guest deposit', $deposits], ['guest refund', $refunds]] as [$label, $rows]) {
            foreach ($rows as $row) {
                $sessionId = (string) ($row->cashier_session_id ?? '');
                if ($sessionId === '') {
                    $unavailable[] = 'CASHIER_SESSION_LINK_EVIDENCE_UNAVAILABLE';
                    $messages[] = ucfirst($label) . " {$row->id} has no cashier session linkage.";
                    continue;
                }
                $ids[] = $sessionId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function evaluateLinkedSessions(
        array $sessionIds,
        \Illuminate\Support\Collection $sessions,
        array &$blockers,
        array &$messages,
        array &$unavailable
    ): void {
        foreach ($sessionIds as $sessionId) {
            $session = $sessions->get($sessionId);
            if (! $session) {
                $unavailable[] = 'CASHIER_SESSION_SOURCE_EVIDENCE_UNAVAILABLE';
                $messages[] = "Cashier session {$sessionId} could not be resolved in the active property.";
                continue;
            }

            if ($session->status === CashierSessionStatusEnum::OPEN) {
                $blockers[] = 'CASHIER_SESSION_OPEN';
                $messages[] = "Cashier session {$sessionId} is OPEN.";
                continue;
            }

            if ($session->status !== CashierSessionStatusEnum::CLOSED) {
                $unavailable[] = 'CASHIER_SESSION_STATUS_EVIDENCE_UNAVAILABLE';
                $messages[] = "Cashier session {$sessionId} has unsupported status.";
                continue;
            }

            if (! $session->closed_at || ! $session->closed_by) {
                $unavailable[] = 'CASHIER_SESSION_CLOSE_EVIDENCE_UNAVAILABLE';
                $messages[] = "Cashier session {$sessionId} is CLOSED without complete close evidence.";
            }
        }
    }

    private function evaluateSourceSnapshots(
        Collection $rows,
        \Illuminate\Support\Collection $sessions,
        string $sourceType,
        array &$reviews,
        array &$messages
    ): void {
        foreach ($rows as $row) {
            $snapshot = $this->snapshotArray($row->source_snapshot);
            $session = $sessions->get((string) $row->cashier_session_id);

            if (! $session || empty($snapshot)) {
                continue;
            }

            $snapshotSessionId = (string) ($snapshot['cashier_session_id'] ?? '');
            $snapshotUserId = (string) ($snapshot['cashier_user_id'] ?? '');
            $snapshotStatus = (string) ($snapshot['cashier_session_status'] ?? '');

            if (
                ($snapshotSessionId !== '' && $snapshotSessionId !== $session->id) ||
                ($snapshotUserId !== '' && $snapshotUserId !== $session->cashier_user_id) ||
                ($snapshotStatus !== '' && $snapshotStatus !== CashierSessionStatusEnum::OPEN->value)
            ) {
                $reviews[] = 'CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT';
                $messages[] = strtoupper($sourceType) . " {$row->id} cashier session snapshot conflicts with current General Cashier evidence.";
            }
        }
    }

    private function snapshotArray(mixed $snapshot): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function accountabilityMarker(array $blockers, array $reviews, array $unavailable): string
    {
        if (! empty($reviews)) {
            return 'CASHIER_ACCOUNTABILITY_REVIEW_REQUIRED';
        }
        if (! empty($unavailable)) {
            return 'CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE';
        }
        if (! empty($blockers)) {
            return 'CASHIER_ACCOUNTABILITY_BLOCKED';
        }

        return 'CASHIER_ACCOUNTABILITY_CLEAR';
    }

    private function determineStatus(
        array $reviews,
        array $unavailable,
        array $blockers
    ): GeneralCashierCheckoutObligationStatusEnum {
        if (! empty($reviews)) {
            return GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired;
        }

        if (! empty($unavailable)) {
            return GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable;
        }

        if (! empty($blockers)) {
            return GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked;
        }

        return GeneralCashierCheckoutObligationStatusEnum::CashierObligationClear;
    }

    private function buildFingerprint(
        string $propertyId,
        string $stayId,
        string $reservationId,
        string $guestId,
        string $status,
        Collection $payments,
        Collection $deposits,
        Collection $refunds,
        array $lifecycleFacts,
        array $sessions,
        array $blockers,
        array $reviews,
        array $unavailable
    ): string {
        $canonical = [
            'identity' => [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId,
                'reservation_id' => $reservationId,
                'guest_id' => $guestId,
                'status' => $status,
            ],
            'payments' => $this->cashSourceFacts($payments, 'payment_number', 'lifecycle_status'),
            'deposits' => $this->cashSourceFacts($deposits, 'deposit_number', 'lifecycle_status'),
            'refunds' => $this->cashSourceFacts($refunds, 'refund_number', 'refund_source_type'),
            'lifecycle_facts' => $this->sortFacts($lifecycleFacts),
            'sessions' => $this->sessionFacts($sessions),
            'codes' => [
                'blockers' => $this->sorted($blockers),
                'reviews' => $this->sorted($reviews),
                'unavailable' => $this->sorted($unavailable),
            ],
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    private function cashSourceFacts(Collection $rows, string $numberColumn, string $statusColumn): array
    {
        return $rows->map(function ($row) use ($numberColumn, $statusColumn): array {
            $status = $row->{$statusColumn};

            return [
                'id' => (string) $row->id,
                'number' => (string) $row->{$numberColumn},
                'cashier_session_id' => (string) $row->cashier_session_id,
                'tender_type' => $row->tender_type?->value ?? (string) $row->tender_type,
                'status' => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
                'amount' => (string) $row->amount,
                'source_snapshot' => $this->snapshotArray($row->source_snapshot),
            ];
        })->values()->all();
    }

    private function sessionFacts(array $sessions): array
    {
        $facts = [];

        foreach ($sessions as $session) {
            $facts[] = [
                'id' => (string) $session->id,
                'cashier_user_id' => (string) $session->cashier_user_id,
                'status' => $session->status?->value ?? (string) $session->status,
                'opened_at' => $session->opened_at?->toIsoString(),
                'opened_by' => (string) $session->opened_by,
                'closed_at' => $session->closed_at?->toIsoString(),
                'closed_by' => (string) ($session->closed_by ?? ''),
            ];
        }

        usort($facts, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $facts;
    }

    private function reversalFacts(Collection $reversals): array
    {
        return $reversals->map(fn ($row): array => [
            'id' => (string) $row->id,
            'type' => $row->reversal_type?->value ?? (string) $row->reversal_type,
            'amount' => $this->amount($row->amount),
            'source_snapshot' => $this->snapshotArray($row->source_snapshot),
        ])->values()->all();
    }

    private function sumAmounts(Collection $rows): string
    {
        $total = '0.00';

        foreach ($rows as $row) {
            $total = bcadd($total, $this->amount($row->amount), 2);
        }

        return $total;
    }

    private function amount(mixed $value): string
    {
        return bcadd((string) $value, '0.00', 2);
    }

    private function sortFacts(array $facts): array
    {
        foreach ($facts as $key => $rows) {
            if (is_array($rows)) {
                usort($rows, fn (array $a, array $b): int => ((string) ($a['id'] ?? '')) <=> ((string) ($b['id'] ?? '')));
                $facts[$key] = $rows;
            }
        }

        ksort($facts);

        return $facts;
    }

    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private function evidenceUnavailable(
        string $propertyId,
        string $stayId,
        string $reservationId,
        string $guestId,
        array $paymentIds,
        array $sessionIds,
        array $markers,
        array $sourceIdentifiers,
        string $code,
        string $message
    ): GeneralCashierCheckoutObligationProjection {
        return GeneralCashierCheckoutObligationProjection::create(
            projection_version: self::PROJECTION_VERSION,
            status: GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable,
            property_id: $propertyId,
            front_desk_stay_id: $stayId,
            reservation_id: $reservationId,
            guest_id: $guestId,
            related_guest_payment_transaction_ids: $paymentIds,
            related_cashier_session_ids: $sessionIds,
            blocker_codes: [],
            blocker_messages: [$message],
            review_reasons: [],
            evidence_unavailable_codes: [$code],
            markers: $markers,
            evaluated_at: now()->toIsoString(),
            source_fingerprint: hash('sha256', implode('|', [
                self::PROJECTION_VERSION,
                $propertyId,
                $stayId,
                $reservationId,
                $guestId,
                $code,
            ])),
            source_identifiers: $sourceIdentifiers,
        );
    }
}

<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestLedgerSettlementReadinessStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutSettlementReadinessProjection;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Throwable;

/**
 * PMS Guest Ledger — Authoritative Checkout Settlement Readiness Projection (GLF-D).
 *
 * Read-only, zero-mutation projection that evaluates guest ledger settlement
 * readiness for a single FrontDeskStay. Executes in a single REPEATABLE READ
 * READ ONLY PostgreSQL transaction.
 *
 * ADR-088 defines the ownership boundary: PMS Guest Ledger owns settlement
 * readiness evidence. GLF-D provides the authoritative read-only projection
 * that Front Desk and future checkout execution packages consume.
 */
class GuestLedgerCheckoutSettlementReadinessProjectionService
{
    public const VIEW_PERMISSION = 'pms.guest-ledger.settlement-readiness.view';
    public const PROJECTION_VERSION = 'GLF-D-1.0';

    public function __construct(
        private readonly CurrentPropertyService                        $currentProperty,
        private readonly GuestLedgerFolioAggregateService              $folioAggregate,
        private readonly GuestLedgerFolioTotalsCalculator               $calculator,
        private readonly GuestLedgerPostingCompletenessReadPort         $postingCompletenessPort,
        private readonly GuestLedgerSettlementHoldReadPort              $settlementHoldPort,
        private readonly GuestLedgerCompletedSettlementConflictReadPort $completedSettlementPort,
    ) {}

    /**
     * Project settlement readiness for a FrontDeskStay.
     *
     * @param  User   $actor            Authenticated actor (must match auth session).
     * @param  string $frontDeskStayId  FrontDeskStay ULID.
     * @return GuestLedgerCheckoutSettlementReadinessProjection
     *
     * @throws AuthorizationException  Actor not authorized.
     * @throws NotFoundException       Stay not found in current property (non-disclosing).
     * @throws DomainException         Cannot guarantee read transaction isolation.
     */
    public function project(User $actor, string $frontDeskStayId): GuestLedgerCheckoutSettlementReadinessProjection
    {
        // ── Authorization (before any data access) ──────────────────────────
        $propertyId = $this->resolveCurrentProperty();
        $this->guardActor($actor, $propertyId);

        // ── REPEATABLE READ READ ONLY transaction ───────────────────────────
        if (DB::transactionLevel() > 0) {
            // Already inside a parent transaction (e.g., test RefreshDatabase or
            // nested service call). Evaluate directly — we cannot change the
            // isolation level mid-transaction. The parent is responsible for
            // consistency guarantees.
            return $this->evaluateProjection($actor, $frontDeskStayId, $propertyId);
        }

        // Top-level call: establish one coherent REPEATABLE READ READ ONLY snapshot.
        return DB::transaction(function () use ($actor, $frontDeskStayId, $propertyId) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->evaluateProjection($actor, $frontDeskStayId, $propertyId);
        });
    }

    /**
     * Internal evaluation — all queries within REPEATABLE READ READ ONLY.
     */
    private function evaluateProjection(User $actor, string $frontDeskStayId, string $propertyId): GuestLedgerCheckoutSettlementReadinessProjection
    {
        // ── Resolve FrontDeskStay (non-disclosing) ──────────────────────────
        $stay = FrontDeskStay::withoutGlobalScope('property')
            ->where('id', $frontDeskStayId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $stay) {
            throw new NotFoundException('FrontDeskStay');
        }

        $evaluatedAt = now()->toIsoString();

        // ── Resolve Reservation and Guest ───────────────────────────────────
        $reservation = Reservation::withoutGlobalScope('property')
            ->where('id', $stay->reservation_id)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation) {
            return $this->evidenceUnavailable(
                $propertyId, $frontDeskStayId, '', '', [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                [$evaluatedAt],
                'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE',
                'Stay-to-Reservation relationship could not be resolved.'
            );
        }

        $guestId = $reservation->primary_guest_id ?? '';
        if (empty($guestId)) {
            return $this->evidenceUnavailable(
                $propertyId, $frontDeskStayId, $reservation->id, '', [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                [$evaluatedAt],
                'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE',
                'Reservation has no primary guest.'
            );
        }

        // ── Resolve checkout-relevant Folios ────────────────────────────────
        $folios = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id)
            ->orderBy('window_number')
            ->get();

        if ($folios->isEmpty()) {
            return $this->evidenceUnavailable(
                $propertyId, $frontDeskStayId, $reservation->id, $guestId, [], null,
                ['folio_scope_marker' => 'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE'],
                [$evaluatedAt],
                'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE',
                'No checkout-relevant folios found for this reservation.'
            );
        }

        // ── Accumulators ────────────────────────────────────────────────────
        $blockerCodes            = [];
        $blockerMessages         = [];
        $reviewReasons           = [];
        $evidenceUnavailableCodes = [];
        $markers                 = [];
        $sourceIdentifiers       = [];
        $folioIds                = [];
        $currencies              = [];
        $aggregateBalance        = '0.00';

        // ── Stay relationship marker ────────────────────────────────────────
        $markers['stay_relationship_marker'] = 'STAY_RESERVATION_GUEST_RESOLVED';

        // ── Evaluate every Folio ────────────────────────────────────────────
        foreach ($folios as $folio) {
            $folioIds[] = $folio->id;

            // Guest mismatch check
            if ($folio->guest_id !== $guestId) {
                $reviewReasons[] = 'FOLIO_RELATIONSHIP_CONFLICT';
                $blockerMessages[] = "Folio {$folio->id} guest does not match reservation primary guest.";
            }

            // Currency tracking
            if (! empty($folio->currency)) {
                $currencies[$folio->currency] = true;
            }

            // Folio lifecycle review
            if ($folio->status === FolioStatusEnum::Closed) {
                $reviewReasons[] = 'FOLIO_LIFECYCLE_REVIEW_REQUIRED';
                $blockerMessages[] = "Folio {$folio->id} is closed without explicit accepted settlement semantics.";
            }

            if ($folio->status === FolioStatusEnum::Void) {
                $reviewReasons[] = 'FOLIO_LIFECYCLE_REVIEW_REQUIRED';
                $blockerMessages[] = "Folio {$folio->id} is void without explicit accepted settlement semantics.";
            }

            // ── Fresh totals calculation ────────────────────────────────────
            $activeItems = FolioItem::where('folio_id', $folio->id)
                ->where('is_void', false)
                ->orderBy('posted_at')
                ->get();

            $fresh = $this->calculator->calculate($activeItems);

            // ── Compare fresh vs cached totals ──────────────────────────────
            $mismatch = false;
            $mismatch = $mismatch || bccomp($fresh['total_charges'], (string) $folio->total_charges, 2) !== 0;
            $mismatch = $mismatch || bccomp($fresh['total_payments'], (string) $folio->total_payments, 2) !== 0;
            $mismatch = $mismatch || bccomp($fresh['total_deposits'], (string) $folio->total_deposits, 2) !== 0;
            $mismatch = $mismatch || bccomp($fresh['total_ar_transfers'], (string) $folio->total_ar_transfers, 2) !== 0;
            // balance is derived — mismatch on any component means balance also mismatches

            if ($mismatch) {
                $reviewReasons[] = 'FOLIO_CACHED_TOTALS_MISMATCH';
                $blockerMessages[] = "Folio {$folio->id} cached totals do not match fresh source-derived calculation.";
            }

            // ── Use fresh balance as source-derived truth ────────────────────
            $folioBalance = $fresh['balance'];
            $aggregateBalance = bcadd($aggregateBalance, $folioBalance, 2);

            // ── Individual Folio zero check ──────────────────────────────────
            if (bccomp($folioBalance, '0.00', 2) !== 0) {
                $blockerCodes[] = 'INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO';
                $blockerMessages[] = "Folio {$folio->id} balance is not zero: {$folioBalance}.";
            }

            $markers['folio_totals_marker'] = 'FRESH_SOURCE_CALCULATED';
        }

        $resolvedCurrency = count($currencies) === 1 ? key($currencies) : null;

        // ── Currency consistency ────────────────────────────────────────────
        if (count($currencies) > 1) {
            $reviewReasons[] = 'FOLIO_CURRENCY_CONFLICT';
            $blockerMessages[] = 'Multiple currencies detected across checkout-relevant folios.';
        }

        // Multi-Folio scope marker
        $markers['folio_scope_marker'] = count($folioIds) > 1
            ? 'MULTI_FOLIO_EVALUATED'
            : 'SINGLE_FOLIO_EVALUATED';

        // ── Guest Payment evaluation ─────────────────────────────────────────
        $this->evaluatePayments(
            $reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockerCodes, $blockerMessages, $reviewReasons
        );

        // ── Guest Deposit evaluation ────────────────────────────────────────
        $this->evaluateDeposits(
            $reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockerCodes, $blockerMessages, $reviewReasons
        );

        // ── Guest Refund evaluation ─────────────────────────────────────────
        $this->evaluateRefunds(
            $reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $blockerCodes, $blockerMessages, $reviewReasons
        );

        // ── AR Transfer evaluation ──────────────────────────────────────────
        $this->evaluateArTransfers(
            $reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockerCodes, $blockerMessages, $reviewReasons
        );

        // ── External evidence ports ─────────────────────────────────────────
        $this->evaluateExternalPorts(
            $reservation->id, $propertyId,
            $blockerCodes, $blockerMessages, $reviewReasons,
            $evidenceUnavailableCodes, $markers, $sourceIdentifiers
        );

        // ── Determine status ────────────────────────────────────────────────
        $status = $this->determineStatus(
            $evidenceUnavailableCodes,
            $reviewReasons,
            $blockerCodes
        );

        // ── Source fingerprint ──────────────────────────────────────────────
        $sourceFingerprint = $this->buildSourceFingerprint(
            $propertyId, $frontDeskStayId, $reservation->id, $guestId,
            $folios, $resolvedCurrency, $status->value,
            $blockerCodes, $reviewReasons, $evidenceUnavailableCodes
        );

        // Collect source identifiers
        $sourceIdentifiers['property_id']       = $propertyId;
        $sourceIdentifiers['front_desk_stay_id'] = $frontDeskStayId;
        $sourceIdentifiers['reservation_id']     = $reservation->id;
        $sourceIdentifiers['guest_id']           = $guestId;

        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version:          self::PROJECTION_VERSION,
            status:                      $status,
            property_id:                 $propertyId,
            front_desk_stay_id:          $frontDeskStayId,
            reservation_id:              $reservation->id,
            guest_id:                    $guestId,
            folio_ids:                   $folioIds,
            folio_count:                 count($folios),
            canonical_aggregate_balance: $aggregateBalance,
            currency:                    $resolvedCurrency,
            blocker_codes:               $blockerCodes,
            blocker_messages:            $blockerMessages,
            review_reasons:              $reviewReasons,
            evidence_unavailable_codes:  $evidenceUnavailableCodes,
            markers:                     $markers,
            evaluated_at:                $evaluatedAt,
            source_fingerprint:          $sourceFingerprint,
            source_identifiers:          $sourceIdentifiers,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private evaluators
    // ─────────────────────────────────────────────────────────────────────────

    private function evaluatePayments(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockerCodes, array &$blockerMessages, array &$reviewReasons
    ): void {
        $payments = GuestPaymentTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->get();

        foreach ($payments as $payment) {
            // Cross-reference checks
            if ($payment->guest_id !== $guestId) {
                $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMessages[] = "Payment {$payment->id} guest does not match reservation primary guest.";
            }

            if ($currency !== null && $payment->currency !== $currency) {
                $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMessages[] = "Payment {$payment->id} currency mismatch.";
            }

            $paymentAmount = bcadd((string) $payment->amount, '0.00', 2);

            // VOIDED check
            if ($payment->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided) {
                // Confirm void evidence exists and no conflicting allocations/refunds
                $hasVoidReversal = GuestPaymentReversal::where('property_id', $propertyId)
                    ->where('guest_payment_transaction_id', $payment->id)
                    ->where('reversal_type', GuestPaymentReversalTypeEnum::PaymentVoid->value)
                    ->exists();

                $hasAllocations = GuestPaymentAllocation::where('property_id', $propertyId)
                    ->where('guest_payment_transaction_id', $payment->id)
                    ->exists();

                $hasRefunds = GuestRefundTransaction::where('property_id', $propertyId)
                    ->where('guest_payment_transaction_id', $payment->id)
                    ->exists();

                if (! $hasVoidReversal && ($hasAllocations || $hasRefunds)) {
                    $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMessages[] = "Payment {$payment->id} is VOIDED but has conflicting allocation/refund evidence.";
                }

                continue;
            }

            // Active allocation total
            $activeAllocated = '0.00';
            $allocations = GuestPaymentAllocation::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $payment->id)
                ->get();

            foreach ($allocations as $allocation) {
                // Check if allocation is reversed
                $reversed = GuestPaymentReversal::where('property_id', $propertyId)
                    ->where('guest_payment_allocation_id', $allocation->id)
                    ->where('reversal_type', GuestPaymentReversalTypeEnum::AllocationReversal->value)
                    ->exists();

                if (! $reversed) {
                    $activeAllocated = bcadd($activeAllocated, bcadd((string) $allocation->amount, '0.00', 2), 2);

                    // Check allocation targets a checkout-relevant Folio
                    if (! in_array($allocation->folio_id, $folioIds, true)) {
                        $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMessages[] = "Payment allocation {$allocation->id} targets non-checkout Folio {$allocation->folio_id}.";
                    }
                }
            }

            // Completed refund total
            $refundedTotal = '0.00';
            $refunds = GuestRefundTransaction::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $payment->id)
                ->get();

            foreach ($refunds as $refund) {
                $refundedTotal = bcadd($refundedTotal, bcadd((string) $refund->amount, '0.00', 2), 2);
            }

            // Resolved amount
            $resolved = bcadd($activeAllocated, $refundedTotal, 2);

            // Source-linked FolioItems check
            $folioItemCount = FolioItem::where('guest_payment_allocation_id', '!=', null)
                ->whereIn('guest_payment_allocation_id', $allocations->pluck('id'))
                ->where('is_void', false)
                ->count();

            if ($folioItemCount !== $allocations->count()) {
                $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMessages[] = "Payment {$payment->id} has missing or duplicate source FolioItems.";
            }

            // Evaluate resolution
            $cmp = bccomp($resolved, $paymentAmount, 2);

            if ($cmp === 0) {
                // Fully resolved — fine
            } elseif ($cmp < 0) {
                // Unresolved remainder
                $blockerCodes[] = 'GUEST_PAYMENT_UNRESOLVED';
                $remainder = bcsub($paymentAmount, $resolved, 2);
                $blockerMessages[] = "Payment {$payment->id} has unresolved remainder {$remainder}.";
            } else {
                // Over-resolved
                $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMessages[] = "Payment {$payment->id} resolved amount exceeds payment amount.";
            }

            // Lifecycle/source consistency
            if ($payment->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided && $cmp !== 0) {
                $reviewReasons[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMessages[] = "Payment {$payment->id} lifecycle status conflicts with derived source state.";
            }
        }
    }

    private function evaluateDeposits(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockerCodes, array &$blockerMessages, array &$reviewReasons
    ): void {
        $deposits = GuestDepositTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->get();

        foreach ($deposits as $deposit) {
            if ($deposit->guest_id !== $guestId) {
                $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMessages[] = "Deposit {$deposit->id} guest does not match reservation primary guest.";
            }

            if ($currency !== null && $deposit->currency !== $currency) {
                $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMessages[] = "Deposit {$deposit->id} currency mismatch.";
            }

            $depositAmount = bcadd((string) $deposit->amount, '0.00', 2);

            // VOIDED check
            if ($deposit->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided) {
                $hasVoidReversal = GuestDepositReversal::where('property_id', $propertyId)
                    ->where('guest_deposit_transaction_id', $deposit->id)
                    ->where('reversal_type', GuestDepositReversalTypeEnum::DepositVoid->value)
                    ->exists();

                $hasApplications = GuestDepositApplication::where('property_id', $propertyId)
                    ->where('guest_deposit_transaction_id', $deposit->id)
                    ->exists();

                $hasRefunds = GuestRefundTransaction::where('property_id', $propertyId)
                    ->where('guest_deposit_transaction_id', $deposit->id)
                    ->exists();

                if (! $hasVoidReversal && ($hasApplications || $hasRefunds)) {
                    $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMessages[] = "Deposit {$deposit->id} is VOIDED but has conflicting application/refund evidence.";
                }

                continue;
            }

            // Active application total
            $activeApplied = '0.00';
            $applications = GuestDepositApplication::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $deposit->id)
                ->get();

            foreach ($applications as $application) {
                $reversed = GuestDepositReversal::where('property_id', $propertyId)
                    ->where('guest_deposit_application_id', $application->id)
                    ->where('reversal_type', GuestDepositReversalTypeEnum::ApplicationReversal->value)
                    ->exists();

                if (! $reversed) {
                    $activeApplied = bcadd($activeApplied, bcadd((string) $application->amount, '0.00', 2), 2);

                    if (! in_array($application->folio_id, $folioIds, true)) {
                        $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMessages[] = "Deposit application {$application->id} targets non-checkout Folio {$application->folio_id}.";
                    }
                }
            }

            // Refund total
            $refundedTotal = '0.00';
            $refunds = GuestRefundTransaction::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $deposit->id)
                ->get();

            foreach ($refunds as $refund) {
                $refundedTotal = bcadd($refundedTotal, bcadd((string) $refund->amount, '0.00', 2), 2);
            }

            $resolved = bcadd($activeApplied, $refundedTotal, 2);

            // Source-linked FolioItems
            $appIds = $applications->pluck('id')->toArray();
            $folioItemCount = FolioItem::where('guest_deposit_application_id', '!=', null)
                ->whereIn('guest_deposit_application_id', $appIds)
                ->where('is_void', false)
                ->count();

            if ($folioItemCount !== $applications->count()) {
                $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMessages[] = "Deposit {$deposit->id} has missing or duplicate source FolioItems.";
            }

            $cmp = bccomp($resolved, $depositAmount, 2);

            if ($cmp === 0) {
                // Resolved
            } elseif ($cmp < 0) {
                $blockerCodes[] = 'GUEST_DEPOSIT_UNRESOLVED';
                $remainder = bcsub($depositAmount, $resolved, 2);
                $blockerMessages[] = "Deposit {$deposit->id} has unresolved remainder {$remainder}.";
            } else {
                $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMessages[] = "Deposit {$deposit->id} resolved amount exceeds deposit amount.";
            }

            if ($deposit->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided && $cmp !== 0) {
                $reviewReasons[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMessages[] = "Deposit {$deposit->id} lifecycle status conflicts with derived source state.";
            }
        }
    }

    private function evaluateRefunds(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array &$blockerCodes, array &$blockerMessages, array &$reviewReasons
    ): void {
        $refunds = GuestRefundTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->get();

        foreach ($refunds as $refund) {
            // Guest and currency checks
            if ($refund->guest_id !== $guestId) {
                $reviewReasons[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMessages[] = "Refund {$refund->id} guest does not match reservation primary guest.";
            }

            if ($currency !== null && $refund->currency !== $currency) {
                $reviewReasons[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMessages[] = "Refund {$refund->id} currency mismatch.";
            }

            // XOR source validation
            $hasPaymentSource = ! empty($refund->guest_payment_transaction_id);
            $hasDepositSource = ! empty($refund->guest_deposit_transaction_id);

            if ($hasPaymentSource === $hasDepositSource) {
                $reviewReasons[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMessages[] = "Refund {$refund->id} must have exactly one Payment or Deposit source.";
            }

            // Verify source exists
            if ($hasPaymentSource) {
                $source = GuestPaymentTransaction::whereKey($refund->guest_payment_transaction_id)
                    ->where('property_id', $propertyId)
                    ->first();
                if (! $source) {
                    $reviewReasons[] = 'REFUND_SOURCE_CONFLICT';
                    $blockerMessages[] = "Refund {$refund->id} Payment source not found.";
                }
            }

            if ($hasDepositSource) {
                $source = GuestDepositTransaction::whereKey($refund->guest_deposit_transaction_id)
                    ->where('property_id', $propertyId)
                    ->first();
                if (! $source) {
                    $reviewReasons[] = 'REFUND_SOURCE_CONFLICT';
                    $blockerMessages[] = "Refund {$refund->id} Deposit source not found.";
                }
            }
        }
    }

    private function evaluateArTransfers(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockerCodes, array &$blockerMessages, array &$reviewReasons
    ): void {
        // AR transfer requests are linked through folios in the checkout set.
        // We scope to folios that belong to this reservation.
        $folioIdsForReservation = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->pluck('id')
            ->toArray();

        $requests = GuestArTransferRequest::where('property_id', $propertyId)
            ->whereIn('folio_id', $folioIdsForReservation)
            ->get();

        foreach ($requests as $request) {
            // Guest and currency checks
            if ($request->guest_id !== $guestId) {
                $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMessages[] = "AR transfer request {$request->id} guest mismatch.";
            }

            if ($currency !== null && $request->currency !== $currency) {
                $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMessages[] = "AR transfer request {$request->id} currency mismatch.";
            }

            // Check Folio is in scope
            if (! in_array($request->folio_id, $folioIds, true)) {
                $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMessages[] = "AR transfer request {$request->id} targets non-checkout Folio {$request->folio_id}.";
            }

            $decisions = GuestArTransferDecision::where('property_id', $propertyId)
                ->where('guest_ar_transfer_request_id', $request->id)
                ->orderBy('created_at')
                ->get();

            match ($request->lifecycle_status) {
                GuestArTransferStatusEnum::Requested => [
                    $blockerCodes[] = 'GUEST_AR_TRANSFER_PENDING',
                    $blockerMessages[] = "AR transfer request {$request->id} has not been decided.",
                ],

                GuestArTransferStatusEnum::Accepted => $this->validateAcceptedArTransfer(
                    $request, $decisions, $propertyId, $folioIds,
                    $blockerCodes, $blockerMessages, $reviewReasons
                ),

                GuestArTransferStatusEnum::Rejected => [
                    // Terminal non-settling — does not permanently block
                    // if Folio is resolved another way.
                ],

                GuestArTransferStatusEnum::Reversed => $this->validateReversedArTransfer(
                    $request, $decisions, $propertyId,
                    $blockerCodes, $blockerMessages, $reviewReasons
                ),
            };

            // Check for conflicting terminal decisions
            $acceptedCount = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Accepted)->count();
            $rejectedCount = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Rejected)->count();
            $reversedCount = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Reversed)->count();

            if (($acceptedCount > 0 && $rejectedCount > 0) || ($acceptedCount > 1 && ! in_array($request->lifecycle_status, [GuestArTransferStatusEnum::Reversed]))) {
                $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMessages[] = "AR transfer request {$request->id} has conflicting terminal decisions.";
            }

            // Status/decision mismatch
            $expectedStatus = match (true) {
                $reversedCount > 0 => GuestArTransferStatusEnum::Reversed,
                $acceptedCount > 0 => GuestArTransferStatusEnum::Accepted,
                $rejectedCount > 0 => GuestArTransferStatusEnum::Rejected,
                default => GuestArTransferStatusEnum::Requested,
            };

            if ($request->lifecycle_status !== $expectedStatus) {
                $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMessages[] = "AR transfer request {$request->id} lifecycle status does not match its decisions.";
            }
        }
    }

    private function validateAcceptedArTransfer(
        GuestArTransferRequest $request,
        $decisions, string $propertyId, array $folioIds,
        array &$blockerCodes, array &$blockerMessages, array &$reviewReasons
    ): void {
        $accepted = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Accepted)->first();

        if (! $accepted) {
            $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMessages[] = "AR transfer request {$request->id} is ACCEPTED but no accepted decision exists.";
            return;
        }

        // Verify exact ArTransfer FolioItem
        $folioItem = FolioItem::where('guest_ar_transfer_decision_id', $accepted->id)
            ->where('is_void', false)
            ->first();

        if (! $folioItem) {
            $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMessages[] = "AR transfer request {$request->id} has accepted decision but no source-linked ArTransfer FolioItem.";
        }

        if ($folioItem && ! in_array($folioItem->folio_id, $folioIds, true)) {
            $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
        }
    }

    private function validateReversedArTransfer(
        GuestArTransferRequest $request,
        $decisions, string $propertyId,
        array &$blockerCodes, array &$blockerMessages, array &$reviewReasons
    ): void {
        $accepted = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Accepted)->first();
        $reversed = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Reversed)->first();

        if (! $accepted) {
            $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMessages[] = "AR transfer request {$request->id} is REVERSED but no accepted decision exists.";
            return;
        }

        if (! $reversed) {
            $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMessages[] = "AR transfer request {$request->id} is REVERSED but no reversal decision exists.";
            return;
        }

        // Verify exact ArTransferReversal FolioItem
        $reversalItem = FolioItem::where('guest_ar_transfer_decision_id', $reversed->id)
            ->where('is_void', false)
            ->first();

        if (! $reversalItem) {
            $reviewReasons[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMessages[] = "AR transfer request {$request->id} has reversal decision but no source-linked ArTransferReversal FolioItem.";
        }
    }

    private function evaluateExternalPorts(
        string $reservationId, string $propertyId,
        array &$blockerCodes, array &$blockerMessages, array &$reviewReasons,
        array &$evidenceUnavailableCodes, array &$markers, array &$sourceIdentifiers
    ): void {
        // ── Posting Completeness ─────────────────────────────────────────────
        $postingResult = $this->postingCompletenessPort->evaluate($reservationId, $propertyId);

        match ($postingResult['status']) {
            GuestLedgerPostingCompletenessReadPort::AVAILABLE_CLEAR => [
                $markers['posting_completeness_marker'] = 'POSTING_COMPLETENESS_CLEAR',
            ],
            GuestLedgerPostingCompletenessReadPort::AVAILABLE_BLOCKED => [
                $blockerCodes[]            = 'MANDATORY_POSTINGS_INCOMPLETE',
                $blockerMessages[]         = $postingResult['message'] ?? 'Mandatory operational postings are incomplete.',
                $markers['posting_completeness_marker'] = 'POSTING_COMPLETENESS_BLOCKED',
            ],
            GuestLedgerPostingCompletenessReadPort::REVIEW_REQUIRED => [
                $reviewReasons[]           = 'POSTING_COMPLETENESS_REVIEW_REQUIRED',
                $blockerMessages[]         = $postingResult['message'] ?? 'Posting completeness requires human review.',
                $markers['posting_completeness_marker'] = 'POSTING_COMPLETENESS_REVIEW_REQUIRED',
            ],
            default => [
                $evidenceUnavailableCodes[] = $postingResult['code'] ?? 'POSTING_COMPLETENESS_EVIDENCE_UNAVAILABLE',
                $blockerMessages[]          = $postingResult['message'] ?? 'Posting completeness evidence is unavailable.',
                $markers['posting_completeness_marker'] = 'POSTING_COMPLETENESS_EVIDENCE_UNAVAILABLE',
            ],
        };

        // ── Settlement Hold ──────────────────────────────────────────────────
        $holdResult = $this->settlementHoldPort->evaluate($reservationId, $propertyId);

        match ($holdResult['status']) {
            GuestLedgerSettlementHoldReadPort::AVAILABLE_CLEAR => [
                $markers['settlement_hold_marker'] = 'SETTLEMENT_HOLD_CLEAR',
            ],
            GuestLedgerSettlementHoldReadPort::AVAILABLE_BLOCKED => [
                $blockerCodes[]            = 'SETTLEMENT_HOLD_ACTIVE',
                $blockerMessages[]         = $holdResult['message'] ?? 'An active settlement hold exists.',
                $markers['settlement_hold_marker'] = 'SETTLEMENT_HOLD_BLOCKED',
            ],
            GuestLedgerSettlementHoldReadPort::REVIEW_REQUIRED => [
                $reviewReasons[]           = 'SETTLEMENT_HOLD_REVIEW_REQUIRED',
                $blockerMessages[]         = $holdResult['message'] ?? 'Settlement hold evidence requires human review.',
                $markers['settlement_hold_marker'] = 'SETTLEMENT_HOLD_REVIEW_REQUIRED',
            ],
            default => [
                $evidenceUnavailableCodes[] = $holdResult['code'] ?? 'SETTLEMENT_HOLD_EVIDENCE_UNAVAILABLE',
                $blockerMessages[]          = $holdResult['message'] ?? 'Settlement hold evidence is unavailable.',
                $markers['settlement_hold_marker'] = 'SETTLEMENT_HOLD_EVIDENCE_UNAVAILABLE',
            ],
        };

        // ── Completed Settlement Conflict ────────────────────────────────────
        $conflictResult = $this->completedSettlementPort->evaluate($reservationId, $propertyId);

        match ($conflictResult['status']) {
            GuestLedgerCompletedSettlementConflictReadPort::AVAILABLE_CLEAR => [
                $markers['completed_settlement_conflict_marker'] = 'COMPLETED_SETTLEMENT_CONFLICT_CLEAR',
            ],
            GuestLedgerCompletedSettlementConflictReadPort::AVAILABLE_BLOCKED => [
                $blockerCodes[]            = 'CONFLICTING_COMPLETED_SETTLEMENT',
                $blockerMessages[]         = $conflictResult['message'] ?? 'A conflicting completed settlement exists.',
                $markers['completed_settlement_conflict_marker'] = 'COMPLETED_SETTLEMENT_CONFLICT_BLOCKED',
            ],
            GuestLedgerCompletedSettlementConflictReadPort::REVIEW_REQUIRED => [
                $reviewReasons[]           = 'COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED',
                $blockerMessages[]         = $conflictResult['message'] ?? 'Completed settlement conflict evidence requires human review.',
                $markers['completed_settlement_conflict_marker'] = 'COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED',
            ],
            default => [
                $evidenceUnavailableCodes[] = $conflictResult['code'] ?? 'COMPLETED_SETTLEMENT_CONFLICT_EVIDENCE_UNAVAILABLE',
                $blockerMessages[]          = $conflictResult['message'] ?? 'Completed settlement conflict evidence is unavailable.',
                $markers['completed_settlement_conflict_marker'] = 'COMPLETED_SETTLEMENT_CONFLICT_EVIDENCE_UNAVAILABLE',
            ],
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Status determination
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Precedence:
     *   1. EVIDENCE_UNAVAILABLE when any mandatory source is unavailable.
     *   2. REVIEW_REQUIRED when no source is unavailable but evidence is ambiguous.
     *   3. BLOCKED when all sources available but requirements unmet.
     *   4. READY only when all pass.
     */
    private function determineStatus(
        array $evidenceUnavailableCodes,
        array $reviewReasons,
        array $blockerCodes
    ): GuestLedgerSettlementReadinessStatusEnum {
        if (! empty($evidenceUnavailableCodes)) {
            return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable;
        }

        if (! empty($reviewReasons)) {
            return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReviewRequired;
        }

        if (! empty($blockerCodes)) {
            return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked;
        }

        return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Source fingerprint
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSourceFingerprint(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        $folios, ?string $currency, string $statusValue,
        array $blockerCodes, array $reviewReasons, array $evidenceUnavailableCodes
    ): string {
        $parts = [];

        $parts[] = "property:{$propertyId}";
        $parts[] = "stay:{$stayId}";
        $parts[] = "reservation:{$reservationId}";
        $parts[] = "guest:{$guestId}";
        $parts[] = "currency:" . ($currency ?? 'null');
        $parts[] = "status:{$statusValue}";

        foreach ($folios as $folio) {
            $parts[] = "folio:{$folio->id}:{$folio->status->value}:{$folio->currency}:{$folio->total_charges}:{$folio->total_payments}:{$folio->total_deposits}:{$folio->total_ar_transfers}:{$folio->balance}";
        }

        $sortedBlockers = $blockerCodes;
        sort($sortedBlockers);
        $parts[] = 'blockers:' . implode(',', $sortedBlockers);

        $sortedReviews = $reviewReasons;
        sort($sortedReviews);
        $parts[] = 'reviews:' . implode(',', $sortedReviews);

        $sortedUnavailable = $evidenceUnavailableCodes;
        sort($sortedUnavailable);
        $parts[] = 'unavailable:' . implode(',', $sortedUnavailable);

        return hash('sha256', implode('|', $parts));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Evidence Unavailable helper
    // ─────────────────────────────────────────────────────────────────────────

    private function evidenceUnavailable(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $folioIds, ?string $currency, array $markers, array $sourceIdentifiers,
        string $code, string $message
    ): GuestLedgerCheckoutSettlementReadinessProjection {
        $evidenceCodes = [$code];
        $messages      = [$message];

        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version:          self::PROJECTION_VERSION,
            status:                      GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
            property_id:                 $propertyId,
            front_desk_stay_id:          $stayId,
            reservation_id:              $reservationId,
            guest_id:                    $guestId,
            folio_ids:                   $folioIds,
            folio_count:                 count($folioIds),
            canonical_aggregate_balance: '0.00',
            currency:                    $currency,
            blocker_codes:               [],
            blocker_messages:            $messages,
            review_reasons:              [],
            evidence_unavailable_codes:  $evidenceCodes,
            markers:                     $markers,
            evaluated_at:                now()->toIsoString(),
            source_fingerprint:          hash('sha256', $code . '|' . $stayId . '|' . $propertyId),
            source_identifiers:          array_merge(
                ['property_id' => $propertyId, 'front_desk_stay_id' => $stayId],
                $sourceIdentifiers
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Authorization helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveCurrentProperty(): string
    {
        $propertyId = session('active_property_id')
            ?? session('current_property_id')
            ?? $this->currentProperty->resolveOrFail();

        $this->currentProperty->setPropertyId($propertyId);
        return $propertyId;
    }

    private function guardActor(User $actor, string $propertyId): void
    {
        // Actor must match authenticated session
        if (! auth()->check() || auth()->id() !== $actor->id) {
            throw new AuthorizationException('Actor identity does not match the authenticated session.');
        }

        // Actor must be active
        $fresh = User::whereKey($actor->id)->where('is_active', true)->first();
        if (! $fresh) {
            throw new AuthorizationException('Settlement readiness projection requires an active actor.');
        }

        // Active property membership
        $hasAccess = $fresh->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasAccess) {
            throw new AuthorizationException('Settlement readiness projection requires active property membership.');
        }

        // Narrow view permission
        try {
            $allowed = $fresh->can(self::VIEW_PERMISSION);
        } catch (Throwable) {
            $allowed = false;
        }

        if (! $allowed) {
            throw new AuthorizationException(
                'Settlement readiness projection requires pms.guest-ledger.settlement-readiness.view permission.'
            );
        }
    }
}

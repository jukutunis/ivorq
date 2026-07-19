<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
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
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;

/**
 * PMS Guest Ledger / PMS Cashiering — Shared Financial Evaluation Service (GLF-E).
 *
 * Extracted from GuestLedgerCheckoutSettlementReadinessProjectionService.
 * Provides two explicit modes:
 *
 *   evaluateSnapshot(...)   — GLF-D compatible; no FOR UPDATE; uses read ports.
 *   evaluateLockedTerminal(...) — GLF-E terminal; FOR UPDATE; uses participation ports.
 *
 * The evaluator performs zero writes. Financial evaluation logic is shared;
 * only the data-access strategy differs between the two modes.
 *
 * Internal result is associative and server-generated. Do not expose through
 * HTTP or Inertia directly.
 */
class GuestLedgerCheckoutFinancialEvaluationService
{
    public function __construct(
        private readonly GuestLedgerFolioTotalsCalculator $calculator,
    ) {}

    // ═════════════════════════════════════════════════════════════════════
    // SNAPSHOT MODE — GLF-D compatible, no FOR UPDATE, read ports
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array<string, mixed>
     */
    public function evaluateSnapshot(
        string $frontDeskStayId,
        string $propertyId,
        GuestLedgerPostingCompletenessReadPort $postingCompletenessPort,
        GuestLedgerSettlementHoldReadPort $settlementHoldPort,
        GuestLedgerCompletedSettlementConflictReadPort $completedSettlementPort,
    ): array {
        return $this->evaluate(
            $frontDeskStayId,
            $propertyId,
            postingCompletenessPort: $postingCompletenessPort,
            settlementHoldPort: $settlementHoldPort,
            completedSettlementPort: $completedSettlementPort,
            useLock: false,
            includeCashFields: false,
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // LOCKED TERMINAL MODE — GLF-E, FOR UPDATE, participation ports
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array<string, mixed>
     */
    public function evaluateLockedTerminal(
        string $frontDeskStayId,
        string $propertyId,
        GuestLedgerPostingCompletenessParticipationPort $postingCompletenessPort,
        GuestLedgerSettlementHoldParticipationPort $settlementHoldPort,
        GuestLedgerCompletedSettlementConflictParticipationPort $completedSettlementPort,
    ): array {
        // Deterministic PMS lock order (section 11)
        // 1. Reservation
        $reservation = Reservation::withoutGlobalScope('property')
            ->where('id', function ($q) use ($frontDeskStayId, $propertyId) {
                $q->select('reservation_id')
                    ->from('front_desk_stays')
                    ->where('id', $frontDeskStayId)
                    ->where('property_id', $propertyId);
            })
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        // 2. Folios — deterministically ordered
        $folios = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id ?? '')
            ->orderBy('window_number')
            ->lockForUpdate()
            ->get();

        $folioIds = $folios->pluck('id')->toArray();

        // 3. Folio Items — deterministically ordered
        $folioItems = FolioItem::whereIn('folio_id', $folioIds)
            ->where('is_void', false)
            ->orderBy('posted_at')
            ->lockForUpdate()
            ->get();

        // 4. Guest Payment Transactions
        $payments = GuestPaymentTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id ?? '')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 5. Guest Payment Allocations
        $paymentAllocations = GuestPaymentAllocation::where('property_id', $propertyId)
            ->whereIn('guest_payment_transaction_id', $payments->pluck('id')->toArray())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 6. Guest Payment Reversals
        $paymentReversals = GuestPaymentReversal::where('property_id', $propertyId)
            ->whereIn('guest_payment_transaction_id', $payments->pluck('id')->toArray())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 7. Guest Deposit Transactions
        $deposits = GuestDepositTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id ?? '')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 8. Guest Deposit Applications
        $depositApplications = GuestDepositApplication::where('property_id', $propertyId)
            ->whereIn('guest_deposit_transaction_id', $deposits->pluck('id')->toArray())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 9. Guest Deposit Reversals
        $depositReversals = GuestDepositReversal::where('property_id', $propertyId)
            ->whereIn('guest_deposit_transaction_id', $deposits->pluck('id')->toArray())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 10. Guest Refund Transactions
        $refunds = GuestRefundTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id ?? '')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 11. Guest AR Transfer Requests
        $arRequests = GuestArTransferRequest::where('property_id', $propertyId)
            ->whereIn('folio_id', $folioIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 12. AR Decisions — read without lock (Finance/AR owned, immutable)
        $arDecisions = GuestArTransferDecision::where('property_id', $propertyId)
            ->whereIn('guest_ar_transfer_request_id', $arRequests->pluck('id')->toArray())
            ->orderBy('created_at')
            ->get();

        return $this->evaluate(
            $frontDeskStayId,
            $propertyId,
            postingCompletenessPort: $postingCompletenessPort,
            settlementHoldPort: $settlementHoldPort,
            completedSettlementPort: $completedSettlementPort,
            useLock: true,
            includeCashFields: true,
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Shared evaluation core — both modes
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array<string, mixed>
     */
    private function evaluate(
        string $frontDeskStayId,
        string $propertyId,
        GuestLedgerPostingCompletenessReadPort|GuestLedgerPostingCompletenessParticipationPort $postingCompletenessPort,
        GuestLedgerSettlementHoldReadPort|GuestLedgerSettlementHoldParticipationPort $settlementHoldPort,
        GuestLedgerCompletedSettlementConflictReadPort|GuestLedgerCompletedSettlementConflictParticipationPort $completedSettlementPort,
        bool $useLock,
        bool $includeCashFields,
    ): array {
        $evaluatedAt = now()->toIsoString();
        $blockers = []; $blockerMsgs = []; $reviews = []; $unavailable = [];
        $markers = []; $sourceIds = [];

        // Stay lookup
        $stay = DB::table('front_desk_stays')
            ->where('id', $frontDeskStayId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $stay) {
            return $this->evidenceUnavailableResult($propertyId, $frontDeskStayId, '', '', [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                $evaluatedAt, 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE');
        }

        // Stay → Reservation → Guest
        $reservation = Reservation::withoutGlobalScope('property')
            ->where('id', $stay->reservation_id)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation || $stay->reservation_id !== $reservation->id) {
            return $this->evidenceUnavailableResult($propertyId, $frontDeskStayId, '', '', [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                $evaluatedAt, 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE');
        }

        $guestId = $reservation->primary_guest_id ?? '';
        if (empty($guestId) || $stay->guest_id !== $guestId) {
            return $this->evidenceUnavailableResult($propertyId, $frontDeskStayId, $reservation->id, $guestId, [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                $evaluatedAt, 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE');
        }

        $markers['stay_relationship_marker'] = 'STAY_RESERVATION_GUEST_RESOLVED';

        // Folios
        $folios = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id)
            ->orderBy('window_number')
            ->get();

        if ($folios->isEmpty()) {
            return $this->evidenceUnavailableResult($propertyId, $frontDeskStayId, $reservation->id, $guestId, [], null,
                ['folio_scope_marker' => 'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE'],
                $evaluatedAt, 'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE');
        }

        $folioIds = []; $currencies = []; $aggregateBalance = '0.00';
        $allFolioFreshTotals = []; $allFolioCachedTotals = [];
        $folioFacts = [];

        foreach ($folios as $folio) {
            $folioIds[] = $folio->id;
            $currencies[$folio->currency ?? ''] = true;
            if ($folio->guest_id !== $guestId) {
                $reviews[] = 'FOLIO_RELATIONSHIP_CONFLICT';
                $blockerMsgs[] = "Folio {$folio->id} guest mismatch.";
            }
            if (in_array($folio->status, [FolioStatusEnum::Closed, FolioStatusEnum::Void], true)) {
                $reviews[] = 'FOLIO_LIFECYCLE_REVIEW_REQUIRED';
                $blockerMsgs[] = "Folio {$folio->id} is {$folio->status->value}.";
            }

            $activeItems = FolioItem::where('folio_id', $folio->id)
                ->where('is_void', false)
                ->orderBy('posted_at')
                ->get();
            $fresh = $this->calculator->calculate($activeItems);
            $allFolioFreshTotals[$folio->id] = $fresh;
            $cached = [
                'total_charges' => (string) $folio->total_charges,
                'total_payments' => (string) $folio->total_payments,
                'total_deposits' => (string) $folio->total_deposits,
                'total_ar_transfers' => (string) $folio->total_ar_transfers,
                'balance' => (string) $folio->balance,
            ];
            $allFolioCachedTotals[$folio->id] = $cached;

            $mismatch = false;
            foreach (['total_charges','total_payments','total_deposits','total_ar_transfers','balance'] as $f) {
                if (bccomp($fresh[$f], $cached[$f], 2) !== 0) { $mismatch = true; break; }
            }
            if ($mismatch) {
                $reviews[] = 'FOLIO_CACHED_TOTALS_MISMATCH';
                $blockerMsgs[] = "Folio {$folio->id} cached totals mismatch.";
            }

            $aggregateBalance = bcadd($aggregateBalance, $fresh['balance'], 2);
            if (bccomp($fresh['balance'], '0.00', 2) !== 0) {
                $blockers[] = 'INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO';
                $blockerMsgs[] = "Folio {$folio->id} balance {$fresh['balance']}.";
            }

            $itemFacts = [];
            foreach ($activeItems as $item) {
                $itemFacts[] = [
                    'id' => $item->id, 'folio_id' => $item->folio_id,
                    'type' => $item->item_type->value, 'amount' => (string) $item->amount,
                    'void' => $item->is_void,
                    'source_domain' => $item->source_domain ?? '',
                    'source_type' => $item->source_type ?? '',
                    'source_id' => $item->source_id ?? '',
                    'payment_alloc_id' => $item->guest_payment_allocation_id ?? '',
                    'payment_rev_id' => $item->guest_payment_reversal_id ?? '',
                    'deposit_app_id' => $item->guest_deposit_application_id ?? '',
                    'deposit_rev_id' => $item->guest_deposit_reversal_id ?? '',
                    'ar_decision_id' => $item->guest_ar_transfer_decision_id ?? '',
                    'reverses_item_id' => $item->reverses_folio_item_id ?? '',
                ];
            }
            usort($itemFacts, fn($a, $b) => $a['id'] <=> $b['id']);
            $folioFacts[$folio->id] = [
                'id' => $folio->id, 'status' => $folio->status->value, 'currency' => $folio->currency,
                'window' => $folio->window_number,
                'fresh' => $fresh, 'cached' => $cached,
                'items' => $itemFacts,
            ];
        }

        $resolvedCurrency = count($currencies) === 1 ? key($currencies) : null;
        if (count($currencies) > 1) {
            $reviews[] = 'FOLIO_CURRENCY_CONFLICT';
            $blockerMsgs[] = 'Multiple currencies across folios.';
        }
        $markers['folio_scope_marker'] = count($folioIds) > 1 ? 'MULTI_FOLIO_EVALUATED' : 'SINGLE_FOLIO_EVALUATED';
        $markers['folio_totals_marker'] = 'FRESH_SOURCE_CALCULATED';

        // Payments
        $paymentFacts = $this->evaluatePayments($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews, $markers, $includeCashFields);

        // Deposits
        $depositFacts = $this->evaluateDeposits($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews, $markers, $includeCashFields);

        // Refunds
        $refundFacts = $this->evaluateRefunds($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $blockers, $blockerMsgs, $reviews, $markers, $includeCashFields);

        // AR Transfers
        $arFacts = $this->evaluateArTransfers($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews, $markers);

        // External ports — use participation or read port depending on mode
        $portFacts = $useLock
            ? $this->evaluateParticipationPorts($reservation->id, $propertyId,
                $postingCompletenessPort, $settlementHoldPort, $completedSettlementPort,
                $blockers, $blockerMsgs, $reviews, $unavailable, $markers, $sourceIds)
            : $this->evaluateReadPorts($reservation->id, $propertyId,
                $postingCompletenessPort, $settlementHoldPort, $completedSettlementPort,
                $blockers, $blockerMsgs, $reviews, $unavailable, $markers, $sourceIds);

        // Status
        $statusValue = $this->determineStatusValue($unavailable, $reviews, $blockers);

        // Cash-linked references (for locked terminal mode only)
        $cashLinkedRefs = [];
        $cashierSessionIds = [];

        if ($useLock) {
            $cashData = $this->buildCashLinkedReferences(
                $propertyId, $reservation->id, $guestId, $paymentFacts, $depositFacts, $refundFacts
            );
            $cashLinkedRefs = $cashData['references'];
            $cashierSessionIds = $cashData['session_ids'];

            // Fail closed if a CASH transaction lacks cashier-session linkage
            if ($cashData['missing_linkage']) {
                $unavailable[] = 'CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE';
                $blockerMsgs[] = 'One or more CASH transactions lack cashier-session linkage.';
            }
        }

        return [
            'property_id' => $propertyId,
            'front_desk_stay_id' => $frontDeskStayId,
            'reservation_id' => $reservation->id,
            'guest_id' => $guestId,
            'folio_ids' => $folioIds,
            'folio_count' => count($folios),
            'canonical_aggregate_balance' => $aggregateBalance,
            'currency' => $resolvedCurrency,
            'blocker_codes' => $blockers,
            'blocker_messages' => $blockerMsgs,
            'review_reasons' => $reviews,
            'evidence_unavailable_codes' => $unavailable,
            'markers' => $markers,
            'folio_facts' => $folioFacts,
            'payment_facts' => $paymentFacts,
            'deposit_facts' => $depositFacts,
            'refund_facts' => $refundFacts,
            'ar_facts' => $arFacts,
            'port_facts' => $portFacts,
            'status_value' => $statusValue,
            'cash_linked_references' => $cashLinkedRefs,
            'cashier_session_ids' => $cashierSessionIds,
            'evaluated_at' => $evaluatedAt,
            'source_ids' => $sourceIds,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // PAYMENTS
    // ═════════════════════════════════════════════════════════════════════

    private function evaluatePayments(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers,
        bool $includeCashFields = false,
    ): array {
        $payments = GuestPaymentTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)->get();
        $facts = []; $allResolved = true; $anyPayment = false;

        foreach ($payments as $p) {
            $anyPayment = true;
            $pAmount = bcadd((string) $p->amount, '0.00', 2);

            if ($p->guest_id !== $guestId) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} guest mismatch.";
            }
            if ($currency !== null && $p->currency !== $currency) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} currency mismatch.";
            }

            $allocations = GuestPaymentAllocation::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $p->id)->get();
            $refunds = GuestRefundTransaction::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $p->id)->get();
            $voidRevs = GuestPaymentReversal::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $p->id)
                ->where('reversal_type', GuestPaymentReversalTypeEnum::PaymentVoid->value)->get();

            if ($p->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided) {
                if ($voidRevs->count() !== 1) {
                    $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Payment {$p->id} VOIDED needs 1 void reversal, found {$voidRevs->count()}.";
                } elseif (bccomp(bcadd((string) $voidRevs[0]->amount, '0.00', 2), $pAmount, 2) !== 0) {
                    $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Payment {$p->id} void reversal amount mismatch.";
                }
                if ($allocations->isNotEmpty() || $refunds->isNotEmpty()) {
                    $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Payment {$p->id} VOIDED with allocs/refunds.";
                }
                $fact = ['id' => $p->id, 'lifecycle' => $p->lifecycle_status->value, 'amount' => $pAmount,
                    'void_rev_count' => $voidRevs->count(), 'alloc_count' => $allocations->count(),
                    'refund_count' => $refunds->count(),
                ];
                if ($includeCashFields) {
                    $fact['tender_type'] = $p->tender_type?->value ?? '';
                    $fact['cashier_session_id'] = $p->cashier_session_id ?? '';
                }
                $facts[] = $fact;
                continue;
            }

            $activeAllocated = '0.00';
            $allocFacts = [];

            foreach ($allocations as $alloc) {
                $revs = GuestPaymentReversal::where('property_id', $propertyId)
                    ->where('guest_payment_allocation_id', $alloc->id)
                    ->where('reversal_type', GuestPaymentReversalTypeEnum::AllocationReversal->value)->get();

                if ($revs->count() > 1) {
                    $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Alloc {$alloc->id} has {$revs->count()} reversals.";
                }

                if ($revs->count() === 1) {
                    $rev = $revs[0];
                    $origItems = FolioItem::where('guest_payment_allocation_id', $alloc->id)
                        ->where('is_void', false)->get();
                    $revItems = FolioItem::where('guest_payment_reversal_id', $rev->id)
                        ->where('is_void', false)->get();

                    if ($origItems->count() !== 1) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Alloc {$alloc->id} has {$origItems->count()} Payment items, expected 1.";
                    }
                    if ($revItems->count() !== 1) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Reversal {$rev->id} has {$revItems->count()} PaymentReversal items, expected 1.";
                    }
                    if ($origItems->count() === 1 && $revItems->count() === 1) {
                        $oi = $origItems[0]; $ri = $revItems[0];
                        if (bccomp((string) $ri->amount, bcadd((string) $alloc->amount, '0.00', 2), 2) !== 0
                            || bccomp((string) $ri->amount, '0.00', 2) <= 0) {
                            $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "Reversal {$rev->id} amount invalid.";
                        }
                        if ($ri->reverses_folio_item_id !== $oi->id) {
                            $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "Reversal {$rev->id} reverses_folio_item_id mismatch.";
                        }
                    }
                    $allocFacts[] = ['alloc_id' => $alloc->id, 'amount' => bcadd((string) $alloc->amount, '0.00', 2),
                        'reversed' => true, 'rev_id' => $rev->id];
                } else {
                    $items = FolioItem::where('guest_payment_allocation_id', $alloc->id)
                        ->where('is_void', false)->get();
                    if ($items->count() !== 1) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Alloc {$alloc->id} has {$items->count()} Payment items, expected 1.";
                    }
                    if ($items->count() === 1) {
                        $item = $items[0];
                        $allocAmt = bcadd((string) $alloc->amount, '0.00', 2);
                        $activeAllocated = bcadd($activeAllocated, $allocAmt, 2);
                        $negAmt = bcmul($allocAmt, '-1', 2);
                        if ($item->property_id !== $propertyId
                            || ! in_array($item->folio_id, $folioIds, true)
                            || $item->item_type !== FolioItemTypeEnum::Payment
                            || bccomp(bcadd((string) $item->amount, '0.00', 2), $negAmt, 2) !== 0) {
                            $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "Alloc {$alloc->id} invalid Payment FolioItem.";
                        }
                        if (! in_array($alloc->folio_id, $folioIds, true)) {
                            $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "Alloc {$alloc->id} outside checkout folios.";
                        }
                    }
                    $allocFacts[] = ['alloc_id' => $alloc->id, 'amount' => bcadd((string) $alloc->amount, '0.00', 2),
                        'reversed' => false, 'folio_id' => $alloc->folio_id];
                }
            }

            $refundedTotal = '0.00';
            foreach ($refunds as $ref) {
                $refundedTotal = bcadd($refundedTotal, bcadd((string) $ref->amount, '0.00', 2), 2);
            }

            $resolved = bcadd($activeAllocated, $refundedTotal, 2);
            $cmp = bccomp($resolved, $pAmount, 2);

            $expectedLc = match (true) {
                bccomp($resolved, '0.00', 2) === 0 => GuestPaymentLifecycleStatusEnum::Recorded,
                bccomp($resolved, $pAmount, 2) < 0 => GuestPaymentLifecycleStatusEnum::PartiallyAllocated,
                bccomp($resolved, $pAmount, 2) === 0 => GuestPaymentLifecycleStatusEnum::FullyAllocated,
                default => null,
            };
            if ($expectedLc !== null && $p->lifecycle_status !== $expectedLc) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} lifecycle {$p->lifecycle_status->value}, expected {$expectedLc->value}.";
            }

            if ($cmp > 0) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} over-resolved.";
            } elseif ($cmp < 0) {
                $blockers[] = 'GUEST_PAYMENT_UNRESOLVED';
                $allResolved = false;
                $blockerMsgs[] = "Payment {$p->id} unresolved " . bcsub($pAmount, $resolved, 2) . ".";
            }

            $fact = ['id' => $p->id, 'lifecycle' => $p->lifecycle_status->value, 'amount' => $pAmount,
                'resolved' => $resolved, 'active_alloc' => $activeAllocated, 'refunded' => $refundedTotal,
                'allocations' => $allocFacts,
            ];
            if ($includeCashFields) {
                $fact['tender_type'] = $p->tender_type?->value ?? '';
                $fact['cashier_session_id'] = $p->cashier_session_id ?? '';
            }
            $facts[] = $fact;
        }

        $markers['payment_resolution_marker'] = (! $anyPayment || $allResolved)
            ? 'PAYMENT_RESOLUTION_COMPLETE' : 'PAYMENT_RESOLUTION_INCOMPLETE';
        return $facts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // DEPOSITS
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateDeposits(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers,
        bool $includeCashFields = false,
    ): array {
        $deposits = GuestDepositTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)->get();
        $facts = []; $allResolved = true; $anyDeposit = false;

        foreach ($deposits as $d) {
            $anyDeposit = true;
            $dAmount = bcadd((string) $d->amount, '0.00', 2);

            if ($d->guest_id !== $guestId) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} guest mismatch.";
            }
            if ($currency !== null && $d->currency !== $currency) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} currency mismatch.";
            }

            $applications = GuestDepositApplication::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $d->id)->get();
            $refunds = GuestRefundTransaction::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $d->id)->get();
            $voidRevs = GuestDepositReversal::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $d->id)
                ->where('reversal_type', GuestDepositReversalTypeEnum::DepositVoid->value)->get();

            if ($d->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided) {
                if ($voidRevs->count() !== 1) {
                    $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Deposit {$d->id} VOIDED needs 1 void reversal, found {$voidRevs->count()}.";
                } elseif (bccomp(bcadd((string) $voidRevs[0]->amount, '0.00', 2), $dAmount, 2) !== 0) {
                    $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Deposit {$d->id} void reversal amount mismatch.";
                }
                if ($applications->isNotEmpty() || $refunds->isNotEmpty()) {
                    $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Deposit {$d->id} VOIDED with apps/refunds.";
                }
                $fact = ['id' => $d->id, 'lifecycle' => $d->lifecycle_status->value, 'amount' => $dAmount,
                    'void_rev_count' => $voidRevs->count(), 'app_count' => $applications->count(),
                    'refund_count' => $refunds->count(),
                ];
                if ($includeCashFields) {
                    $fact['tender_type'] = $d->tender_type?->value ?? '';
                    $fact['cashier_session_id'] = $d->cashier_session_id ?? '';
                }
                $facts[] = $fact;
                continue;
            }

            $activeApplied = '0.00';
            $appFacts = [];

            foreach ($applications as $app) {
                $revs = GuestDepositReversal::where('property_id', $propertyId)
                    ->where('guest_deposit_application_id', $app->id)
                    ->where('reversal_type', GuestDepositReversalTypeEnum::ApplicationReversal->value)->get();

                if ($revs->count() > 1) {
                    $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "App {$app->id} has {$revs->count()} reversals.";
                }

                if ($revs->count() === 1) {
                    $rev = $revs[0];
                    $origItems = FolioItem::where('guest_deposit_application_id', $app->id)
                        ->where('is_void', false)->get();
                    $revItems = FolioItem::where('guest_deposit_reversal_id', $rev->id)
                        ->where('is_void', false)->get();
                    if ($origItems->count() !== 1) {
                        $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "App {$app->id} has {$origItems->count()} Deposit items, expected 1.";
                    }
                    if ($revItems->count() !== 1) {
                        $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Dep reversal {$rev->id} has {$revItems->count()} items, expected 1.";
                    }
                    if ($origItems->count() === 1 && $revItems->count() === 1) {
                        $oi = $origItems[0]; $ri = $revItems[0];
                        if (bccomp((string) $ri->amount, bcadd((string) $app->amount, '0.00', 2), 2) !== 0
                            || bccomp((string) $ri->amount, '0.00', 2) <= 0) {
                            $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "Dep reversal {$rev->id} amount invalid.";
                        }
                        if ($ri->reverses_folio_item_id !== $oi->id) {
                            $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "Dep reversal {$rev->id} reverses_folio_item_id mismatch.";
                        }
                    }
                    $appFacts[] = ['app_id' => $app->id, 'amount' => bcadd((string) $app->amount, '0.00', 2),
                        'reversed' => true, 'rev_id' => $rev->id];
                } else {
                    $items = FolioItem::where('guest_deposit_application_id', $app->id)
                        ->where('is_void', false)->get();
                    if ($items->count() !== 1) {
                        $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "App {$app->id} has {$items->count()} Deposit items, expected 1.";
                    }
                    if ($items->count() === 1) {
                        $item = $items[0];
                        $appAmt = bcadd((string) $app->amount, '0.00', 2);
                        $activeApplied = bcadd($activeApplied, $appAmt, 2);
                        $negAmt = bcmul($appAmt, '-1', 2);
                        if ($item->property_id !== $propertyId
                            || ! in_array($item->folio_id, $folioIds, true)
                            || $item->item_type !== FolioItemTypeEnum::Deposit
                            || bccomp(bcadd((string) $item->amount, '0.00', 2), $negAmt, 2) !== 0) {
                            $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                            $blockerMsgs[] = "App {$app->id} invalid Deposit FolioItem.";
                        }
                    }
                    $appFacts[] = ['app_id' => $app->id, 'amount' => bcadd((string) $app->amount, '0.00', 2),
                        'reversed' => false, 'folio_id' => $app->folio_id];
                }
            }

            $refundedTotal = '0.00';
            foreach ($refunds as $ref) {
                $refundedTotal = bcadd($refundedTotal, bcadd((string) $ref->amount, '0.00', 2), 2);
            }

            $resolved = bcadd($activeApplied, $refundedTotal, 2);
            $cmp = bccomp($resolved, $dAmount, 2);

            $expectedLc = match (true) {
                bccomp($resolved, '0.00', 2) === 0 => GuestDepositLifecycleStatusEnum::Recorded,
                bccomp($resolved, $dAmount, 2) < 0 => GuestDepositLifecycleStatusEnum::PartiallyResolved,
                bccomp($resolved, $dAmount, 2) === 0 => GuestDepositLifecycleStatusEnum::Resolved,
                default => null,
            };
            if ($expectedLc !== null && $d->lifecycle_status !== $expectedLc) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} lifecycle {$d->lifecycle_status->value}, expected {$expectedLc->value}.";
            }

            if ($cmp > 0) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} over-resolved.";
            } elseif ($cmp < 0) {
                $blockers[] = 'GUEST_DEPOSIT_UNRESOLVED';
                $allResolved = false;
                $blockerMsgs[] = "Deposit {$d->id} unresolved " . bcsub($dAmount, $resolved, 2) . ".";
            }

            $fact = ['id' => $d->id, 'lifecycle' => $d->lifecycle_status->value, 'amount' => $dAmount,
                'resolved' => $resolved, 'applications' => $appFacts,
            ];
            if ($includeCashFields) {
                $fact['tender_type'] = $d->tender_type?->value ?? '';
                $fact['cashier_session_id'] = $d->cashier_session_id ?? '';
            }
            $facts[] = $fact;
        }

        $markers['deposit_resolution_marker'] = (! $anyDeposit || $allResolved)
            ? 'DEPOSIT_RESOLUTION_COMPLETE' : 'DEPOSIT_RESOLUTION_INCOMPLETE';
        return $facts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // REFUNDS
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateRefunds(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers,
        bool $includeCashFields = false,
    ): array {
        $refunds = GuestRefundTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)->get();
        $facts = []; $anyIssue = false;

        foreach ($refunds as $r) {
            $rAmount = bcadd((string) $r->amount, '0.00', 2);
            if ($r->property_id !== $propertyId || $r->reservation_id !== $reservationId) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} scope mismatch."; $anyIssue = true; continue;
            }
            if ($r->guest_id !== $guestId) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} guest mismatch."; $anyIssue = true;
            }
            if ($currency !== null && $r->currency !== $currency) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} currency mismatch."; $anyIssue = true;
            }
            if (bccomp($rAmount, '0.00', 2) <= 0) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} amount not positive."; $anyIssue = true;
            }

            $srcType = $r->refund_source_type?->value ?? '';
            $hasPay = ! empty($r->guest_payment_transaction_id);
            $hasDep = ! empty($r->guest_deposit_transaction_id);
            $expectedType = $hasPay ? 'GUEST_PAYMENT' : ($hasDep ? 'GUEST_DEPOSIT' : 'UNKNOWN');

            if (! $hasPay && ! $hasDep) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} has no source."; $anyIssue = true; continue;
            }
            if ($hasPay && $hasDep) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} has multiple sources."; $anyIssue = true; continue;
            }
            if ($srcType !== $expectedType) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} source_type {$srcType} disagrees with FK ({$expectedType}).";
                $anyIssue = true;
            }

            if ($hasPay) {
                $src = GuestPaymentTransaction::whereKey($r->guest_payment_transaction_id)
                    ->where('property_id', $propertyId)->first();
                if (! $src || $src->reservation_id !== $reservationId
                    || $src->guest_id !== $guestId
                    || ($currency !== null && $src->currency !== $currency)) {
                    $reviews[] = 'REFUND_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Refund {$r->id} Payment source invalid."; $anyIssue = true;
                }
            }
            if ($hasDep) {
                $src = GuestDepositTransaction::whereKey($r->guest_deposit_transaction_id)
                    ->where('property_id', $propertyId)->first();
                if (! $src || $src->reservation_id !== $reservationId
                    || $src->guest_id !== $guestId
                    || ($currency !== null && $src->currency !== $currency)) {
                    $reviews[] = 'REFUND_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Refund {$r->id} Deposit source invalid."; $anyIssue = true;
                }
            }

            $fact = ['id' => $r->id, 'source_type' => $srcType, 'amount' => $rAmount,
                'payment_id' => $r->guest_payment_transaction_id ?? '',
                'deposit_id' => $r->guest_deposit_transaction_id ?? '',
                'currency' => $r->currency, 'guest_id' => $r->guest_id,
            ];
            if ($includeCashFields) {
                $fact['tender_type'] = $r->tender_type?->value ?? '';
                $fact['cashier_session_id'] = $r->cashier_session_id ?? '';
            }
            $facts[] = $fact;
        }

        $markers['refund_resolution_marker'] = $anyIssue
            ? 'REFUND_RESOLUTION_REVIEW_REQUIRED' : 'REFUND_RESOLUTION_TERMINAL';
        return $facts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // AR TRANSFERS
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateArTransfers(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers
    ): array {
        $folioIdsForRes = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)->where('reservation_id', $reservationId)
            ->pluck('id')->toArray();
        $requests = GuestArTransferRequest::where('property_id', $propertyId)
            ->whereIn('folio_id', $folioIdsForRes)->get();
        $facts = []; $anyBlock = false; $anyReview = false;

        foreach ($requests as $req) {
            $reqAmount = bcadd((string) $req->amount, '0.00', 2);
            if ($req->guest_id !== $guestId || ($currency !== null && $req->currency !== $currency)) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} guest/currency mismatch."; $anyReview = true;
            }
            if (! in_array($req->folio_id, $folioIds, true)) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} outside checkout folios."; $anyReview = true;
            }

            $decisions = GuestArTransferDecision::where('property_id', $propertyId)
                ->where('guest_ar_transfer_request_id', $req->id)->orderBy('created_at')->get();
            $accepted  = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Accepted);
            $rejected  = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Rejected);
            $reversed  = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Reversed);

            match ($req->lifecycle_status) {
                GuestArTransferStatusEnum::Requested => $this->arRequested($req, $decisions, $blockers, $blockerMsgs, $reviews, $anyBlock, $anyReview),
                GuestArTransferStatusEnum::Accepted  => $this->arAccepted($req, $accepted, $rejected, $reversed, $propertyId, $folioIds, $blockers, $blockerMsgs, $reviews, $anyReview),
                GuestArTransferStatusEnum::Rejected  => $this->arRejected($req, $accepted, $rejected, $reviews, $blockerMsgs, $anyReview),
                GuestArTransferStatusEnum::Reversed  => $this->arReversed($req, $accepted, $reversed, $propertyId, $blockers, $blockerMsgs, $reviews, $anyReview),
            };

            if ($accepted->count() > 0 && $rejected->count() > 0) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} conflicting accepted+rejected."; $anyReview = true;
            }
            if ($accepted->count() > 1 && $req->lifecycle_status !== GuestArTransferStatusEnum::Reversed) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} has {$accepted->count()} accepted, expected 1."; $anyReview = true;
            }

            $facts[] = ['id' => $req->id, 'lifecycle' => $req->lifecycle_status->value, 'amount' => $reqAmount,
                'folio_id' => $req->folio_id, 'accepted_count' => $accepted->count(),
                'rejected_count' => $rejected->count(), 'reversed_count' => $reversed->count()];
        }

        $markers['ar_transfer_marker'] = $anyBlock ? 'AR_TRANSFER_BLOCKED'
            : ($anyReview ? 'AR_TRANSFER_REVIEW_REQUIRED' : 'AR_TRANSFER_CLEAR');
        return $facts;
    }

    private function arRequested($req, $decisions, &$blockers, &$blockerMsgs, &$reviews, &$anyBlock, &$anyReview): void {
        $blockers[] = 'GUEST_AR_TRANSFER_PENDING'; $blockerMsgs[] = "AR {$req->id} pending."; $anyBlock = true;
        if ($decisions->isNotEmpty()) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REQUESTED but has decisions."; $anyReview = true;
        }
    }

    private function arAccepted($req, $accepted, $rejected, $reversed, $propertyId, $folioIds, &$blockers, &$blockerMsgs, &$reviews, &$anyReview): void {
        if ($rejected->isNotEmpty() || $reversed->isNotEmpty()) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} ACCEPTED with conflicting decisions."; $anyReview = true;
        }
        if ($accepted->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} ACCEPTED has {$accepted->count()} accepted, expected 1."; $anyReview = true;
            return;
        }
        $acc = $accepted->first();
        $items = FolioItem::where('guest_ar_transfer_decision_id', $acc->id)->where('is_void', false)->get();
        if ($items->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} ACCEPTED has {$items->count()} ArTransfer items, expected 1.";
            $anyReview = true; return;
        }
        $item = $items[0];
        $reqAmount = bcadd((string) $req->amount, '0.00', 2);
        $negAmt = bcmul($reqAmount, '-1', 2);
        if ($item->property_id !== $propertyId
            || ! in_array($item->folio_id, $folioIds, true)
            || $item->item_type !== FolioItemTypeEnum::ArTransfer
            || bccomp(bcadd((string) $item->amount, '0.00', 2), $negAmt, 2) !== 0) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} ACCEPTED invalid ArTransfer FolioItem."; $anyReview = true;
        }
    }

    private function arRejected($req, $accepted, $rejected, &$reviews, &$blockerMsgs, &$anyReview): void {
        if ($accepted->isNotEmpty()) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REJECTED with accepted."; $anyReview = true;
        }
        if ($rejected->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REJECTED has {$rejected->count()} rejected, expected 1."; $anyReview = true;
        }
    }

    private function arReversed($req, $accepted, $reversed, $propertyId, &$blockers, &$blockerMsgs, &$reviews, &$anyReview): void {
        if ($accepted->count() !== 1 || $reversed->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REVERSED needs 1 accepted + 1 reversed."; $anyReview = true;
            return;
        }
        $acc = $accepted->first(); $rev = $reversed->first();
        if ($rev->reverses_decision_id !== $acc->id) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} reversal not linked to accepted."; $anyReview = true;
        }
        $origItems = FolioItem::where('guest_ar_transfer_decision_id', $acc->id)->where('is_void', false)->get();
        $revItems  = FolioItem::where('guest_ar_transfer_decision_id', $rev->id)->where('is_void', false)->get();
        if ($origItems->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REVERSED has {$origItems->count()} orig items, expected 1."; $anyReview = true;
        }
        if ($revItems->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REVERSED has {$revItems->count()} reversal items, expected 1."; $anyReview = true;
        }
        if ($origItems->count() === 1 && $revItems->count() === 1) {
            $oi = $origItems[0]; $ri = $revItems[0];
            if ($oi->item_type !== FolioItemTypeEnum::ArTransfer || $ri->item_type !== FolioItemTypeEnum::ArTransferReversal) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} REVERSED item types invalid."; $anyReview = true;
            }
            if ($ri->reverses_folio_item_id !== $oi->id) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} reversal folio_item linkage invalid."; $anyReview = true;
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // External ports — GLF-D read mode
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateReadPorts(
        string $reservationId, string $propertyId,
        $postingCompletenessPort, $settlementHoldPort, $completedSettlementPort,
        array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$unavailable, array &$markers, array &$sourceIds
    ): array {
        $portFacts = [];
        foreach ([
            ['port' => $postingCompletenessPort, 'blocker' => 'MANDATORY_POSTINGS_INCOMPLETE',
             'review' => 'POSTING_COMPLETENESS_REVIEW_REQUIRED', 'marker' => 'posting_completeness_marker',
             'key' => 'posting_completeness'],
            ['port' => $settlementHoldPort, 'blocker' => 'SETTLEMENT_HOLD_ACTIVE',
             'review' => 'SETTLEMENT_HOLD_REVIEW_REQUIRED', 'marker' => 'settlement_hold_marker',
             'key' => 'settlement_hold'],
            ['port' => $completedSettlementPort, 'blocker' => 'CONFLICTING_COMPLETED_SETTLEMENT',
             'review' => 'COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED', 'marker' => 'completed_settlement_conflict_marker',
             'key' => 'completed_settlement_conflict'],
        ] as $cfg) {
            $result = $cfg['port']->evaluate($reservationId, $propertyId);
            $sourceIds[$cfg['key'] . '_status'] = $result['status'];
            $sourceIds[$cfg['key'] . '_code'] = $result['code'] ?? 'null';
            $portFacts[$cfg['key']] = ['status' => $result['status'], 'code' => $result['code'] ?? 'null'];

            match ($result['status']) {
                'AVAILABLE_CLEAR' => $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_CLEAR',
                'AVAILABLE_BLOCKED' => (function () use ($cfg, $result, &$blockers, &$blockerMsgs, &$markers) {
                    $blockers[] = $cfg['blocker']; $blockerMsgs[] = $result['message'] ?? $cfg['blocker'];
                    $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_BLOCKED';
                })(),
                'REVIEW_REQUIRED' => (function () use ($cfg, $result, &$reviews, &$blockerMsgs, &$markers) {
                    $reviews[] = $cfg['review']; $blockerMsgs[] = $result['message'] ?? $cfg['review'];
                    $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_REVIEW_REQUIRED';
                })(),
                default => (function () use ($cfg, $result, &$unavailable, &$blockerMsgs, &$markers) {
                    $unavailable[] = $result['code'] ?? strtoupper($cfg['key']) . '_EVIDENCE_UNAVAILABLE';
                    $blockerMsgs[] = $result['message'] ?? ($cfg['key'] . ' evidence unavailable.');
                    $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_EVIDENCE_UNAVAILABLE';
                })(),
            };
        }
        return $portFacts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // External ports — GLF-E locked participation mode
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateParticipationPorts(
        string $reservationId, string $propertyId,
        $postingCompletenessPort, $settlementHoldPort, $completedSettlementPort,
        array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$unavailable, array &$markers, array &$sourceIds
    ): array {
        $portFacts = [];
        foreach ([
            ['port' => $postingCompletenessPort, 'blocker' => 'MANDATORY_POSTINGS_INCOMPLETE',
             'review' => 'POSTING_COMPLETENESS_REVIEW_REQUIRED', 'marker' => 'posting_completeness_marker',
             'key' => 'posting_completeness'],
            ['port' => $settlementHoldPort, 'blocker' => 'SETTLEMENT_HOLD_ACTIVE',
             'review' => 'SETTLEMENT_HOLD_REVIEW_REQUIRED', 'marker' => 'settlement_hold_marker',
             'key' => 'settlement_hold'],
            ['port' => $completedSettlementPort, 'blocker' => 'CONFLICTING_COMPLETED_SETTLEMENT',
             'review' => 'COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED', 'marker' => 'completed_settlement_conflict_marker',
             'key' => 'completed_settlement_conflict'],
        ] as $cfg) {
            $result = $cfg['port']->participate($reservationId, $propertyId);
            $sourceIds[$cfg['key'] . '_status'] = $result['status'];
            $sourceIds[$cfg['key'] . '_code'] = $result['code'] ?? 'null';
            $portFacts[$cfg['key']] = [
                'status' => $result['status'],
                'code' => $result['code'] ?? 'null',
                'source_fingerprint' => $result['source_fingerprint'] ?? null,
            ];

            match ($result['status']) {
                'AVAILABLE_CLEAR' => $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_CLEAR',
                'AVAILABLE_BLOCKED' => (function () use ($cfg, $result, &$blockers, &$blockerMsgs, &$markers) {
                    $blockers[] = $cfg['blocker'];
                    $blockerMsgs[] = $cfg['blocker'];
                    $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_BLOCKED';
                })(),
                'REVIEW_REQUIRED' => (function () use ($cfg, $result, &$reviews, &$blockerMsgs, &$markers) {
                    $reviews[] = $cfg['review'];
                    $blockerMsgs[] = $cfg['review'];
                    $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_REVIEW_REQUIRED';
                })(),
                default => (function () use ($cfg, $result, &$unavailable, &$blockerMsgs, &$markers) {
                    $unavailable[] = $result['code'] ?? strtoupper($cfg['key']) . '_EVIDENCE_UNAVAILABLE';
                    $blockerMsgs[] = $cfg['key'] . ' evidence unavailable.';
                    $markers[$cfg['marker']] = strtoupper($cfg['key']) . '_EVIDENCE_UNAVAILABLE';
                })(),
            };
        }
        return $portFacts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Cash-linked references (locked terminal mode only)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array{references: array, session_ids: array, missing_linkage: bool}
     */
    private function buildCashLinkedReferences(
        string $propertyId, string $reservationId, string $guestId,
        array $paymentFacts, array $depositFacts, array $refundFacts,
    ): array {
        $references = [];
        $sessionIds = [];
        $missingLinkage = false;

        // CASH payments
        foreach ($paymentFacts as $p) {
            $tenderType = $p['tender_type'] ?? '';
            if ($tenderType === 'CASH') {
                $csId = $p['cashier_session_id'] ?? '';
                if (empty($csId)) {
                    $missingLinkage = true;
                    continue;
                }
                $references[] = [
                    'source_type' => 'GUEST_PAYMENT_TRANSACTION',
                    'source_id' => $p['id'],
                    'cashier_session_id' => $csId,
                ];
                $sessionIds[] = $csId;
            }
        }

        // CASH deposits
        foreach ($depositFacts as $d) {
            $tenderType = $d['tender_type'] ?? '';
            if ($tenderType === 'CASH') {
                $csId = $d['cashier_session_id'] ?? '';
                if (empty($csId)) {
                    $missingLinkage = true;
                    continue;
                }
                $references[] = [
                    'source_type' => 'GUEST_DEPOSIT_TRANSACTION',
                    'source_id' => $d['id'],
                    'cashier_session_id' => $csId,
                ];
                $sessionIds[] = $csId;
            }
        }

        // CASH refunds
        foreach ($refundFacts as $r) {
            $tenderType = $r['tender_type'] ?? '';
            if ($tenderType === 'CASH') {
                $csId = $r['cashier_session_id'] ?? '';
                if (empty($csId)) {
                    $missingLinkage = true;
                    continue;
                }
                $references[] = [
                    'source_type' => 'GUEST_REFUND_TRANSACTION',
                    'source_id' => $r['id'],
                    'cashier_session_id' => $csId,
                ];
                $sessionIds[] = $csId;
            }
        }

        // Sort deterministically
        usort($references, function (array $a, array $b): int {
            return ($a['source_type'] . $a['source_id'] . $a['cashier_session_id'])
                <=> ($b['source_type'] . $b['source_id'] . $b['cashier_session_id']);
        });

        $sessionIds = array_values(array_unique($sessionIds));
        sort($sessionIds);

        return [
            'references' => $references,
            'session_ids' => $sessionIds,
            'missing_linkage' => $missingLinkage,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Status
    // ═════════════════════════════════════════════════════════════════════

    public function determineStatusValue(array $unavailable, array $reviews, array $blockers): string
    {
        if (! empty($unavailable)) return 'PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE';
        if (! empty($reviews))    return 'PMS_TERMINAL_FINANCIAL_REVIEW_REQUIRED';
        if (! empty($blockers))   return 'PMS_TERMINAL_FINANCIAL_BLOCKED';
        return 'PMS_TERMINAL_FINANCIAL_READY';
    }

    // ═════════════════════════════════════════════════════════════════════
    // Fingerprint — deterministic source-fact hash
    // ═════════════════════════════════════════════════════════════════════

    public function buildTerminalFingerprint(
        string $propertyId, string $stayId, string $reservationId,
        string $propertyBusinessDateId, string $businessDate,
        array $folioFacts, array $paymentFacts, array $depositFacts,
        array $refundFacts, array $arFacts, array $portFacts,
        array $cashLinkedReferences, ?string $currency, string $statusValue,
        string $postgresTransactionId,
    ): string {
        $canonical = [];

        // Version and top-level identities
        $top = [
            'v' => 'GLF-E-PMS-TERMINAL-FINANCIAL-v1',
            'p' => $propertyId,
            's' => $stayId,
            'r' => $reservationId,
            'pbd' => $propertyBusinessDateId,
            'bd' => $businessDate,
            'cur' => $currency ?? 'null',
            'st' => $statusValue,
        ];
        ksort($top);
        $canonical[] = json_encode($top, JSON_UNESCAPED_SLASHES);

        // Folios
        $ffKeys = array_keys($folioFacts); sort($ffKeys);
        foreach ($ffKeys as $fid) {
            $f = $folioFacts[$fid];
            $canonical[] = json_encode([
                'fid' => $fid, 'status' => $f['status'], 'currency' => $f['currency'],
                'fresh' => $f['fresh'], 'cached' => $f['cached'],
                'items' => $f['items'],
            ], JSON_UNESCAPED_SLASHES);
        }

        // Payments
        usort($paymentFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($paymentFacts, JSON_UNESCAPED_SLASHES);

        // Deposits
        usort($depositFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($depositFacts, JSON_UNESCAPED_SLASHES);

        // Refunds
        usort($refundFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($refundFacts, JSON_UNESCAPED_SLASHES);

        // AR transfers
        usort($arFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($arFacts, JSON_UNESCAPED_SLASHES);

        // External ports
        $pfKeys = array_keys($portFacts); sort($pfKeys);
        $pfSorted = []; foreach ($pfKeys as $k) { $pfSorted[$k] = $portFacts[$k]; }
        $canonical[] = json_encode($pfSorted, JSON_UNESCAPED_SLASHES);

        // Cash-linked references
        $canonical[] = json_encode($cashLinkedReferences, JSON_UNESCAPED_SLASHES);

        // PostgreSQL transaction identity (one-way hash)
        $canonical[] = hash('sha256', $postgresTransactionId);

        return hash('sha256', implode('|', $canonical));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Snapshot-compatible fingerprint (for GLF-D equivalence)
    // ═════════════════════════════════════════════════════════════════════

    public function buildSnapshotFingerprint(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $folioFacts, array $paymentFacts, array $depositFacts, array $refundFacts,
        array $arFacts, array $portFacts, ?string $currency, string $statusValue,
    ): string {
        $canonical = [];

        $top = ['p' => $propertyId, 's' => $stayId, 'r' => $reservationId, 'g' => $guestId,
                'cur' => $currency ?? 'null', 'st' => $statusValue];
        ksort($top);
        $canonical[] = json_encode($top, JSON_UNESCAPED_SLASHES);

        $ffKeys = array_keys($folioFacts); sort($ffKeys);
        foreach ($ffKeys as $fid) {
            $f = $folioFacts[$fid];
            $canonical[] = json_encode([
                'fid' => $fid, 'status' => $f['status'], 'currency' => $f['currency'],
                'fresh' => $f['fresh'], 'cached' => $f['cached'],
                'items' => $f['items'],
            ], JSON_UNESCAPED_SLASHES);
        }

        usort($paymentFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($paymentFacts, JSON_UNESCAPED_SLASHES);

        usort($depositFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($depositFacts, JSON_UNESCAPED_SLASHES);

        usort($refundFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($refundFacts, JSON_UNESCAPED_SLASHES);

        usort($arFacts, fn($a, $b) => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        $canonical[] = json_encode($arFacts, JSON_UNESCAPED_SLASHES);

        $pfKeys = array_keys($portFacts); sort($pfKeys);
        $pfSorted = []; foreach ($pfKeys as $k) { $pfSorted[$k] = $portFacts[$k]; }
        $canonical[] = json_encode($pfSorted, JSON_UNESCAPED_SLASHES);

        return hash('sha256', implode('|', $canonical));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Evidence unavailable helper
    // ═════════════════════════════════════════════════════════════════════

    private function evidenceUnavailableResult(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $folioIds, ?string $currency, array $markers, string $evaluatedAt, string $code,
    ): array {
        return [
            'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'folio_ids' => $folioIds,
            'folio_count' => count($folioIds),
            'canonical_aggregate_balance' => '0.00',
            'currency' => $currency,
            'blocker_codes' => [],
            'blocker_messages' => [],
            'review_reasons' => [],
            'evidence_unavailable_codes' => [$code],
            'markers' => $markers,
            'folio_facts' => [],
            'payment_facts' => [],
            'deposit_facts' => [],
            'refund_facts' => [],
            'ar_facts' => [],
            'port_facts' => [],
            'status_value' => 'PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE',
            'cash_linked_references' => [],
            'cashier_session_ids' => [],
            'evaluated_at' => $evaluatedAt,
            'source_ids' => [],
        ];
    }
}

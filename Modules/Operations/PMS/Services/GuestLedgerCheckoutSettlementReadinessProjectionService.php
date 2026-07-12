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

class GuestLedgerCheckoutSettlementReadinessProjectionService
{
    public const VIEW_PERMISSION = 'pms.guest-ledger.settlement-readiness.view';
    public const PROJECTION_VERSION = 'GLF-D-1.1';

    private const STABLE_ERROR_NESTED_TX = 'GLF_D_REQUIRES_TOP_LEVEL_READ_TRANSACTION';

    public function __construct(
        private readonly CurrentPropertyService                        $currentProperty,
        private readonly GuestLedgerFolioTotalsCalculator               $calculator,
        private readonly GuestLedgerPostingCompletenessReadPort         $postingCompletenessPort,
        private readonly GuestLedgerSettlementHoldReadPort              $settlementHoldPort,
        private readonly GuestLedgerCompletedSettlementConflictReadPort $completedSettlementPort,
    ) {}

    public function project(User $actor, string $frontDeskStayId): GuestLedgerCheckoutSettlementReadinessProjection
    {
        $propertyId = $this->resolveCurrentProperty();
        $this->guardActor($actor, $propertyId);

        // ── Strict top-level transaction contract ──────────────────────────
        if (DB::transactionLevel() > 0) {
            throw new DomainException(self::STABLE_ERROR_NESTED_TX);
        }

        return DB::transaction(function () use ($actor, $frontDeskStayId, $propertyId) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $this->evaluateProjection($actor, $frontDeskStayId, $propertyId);
        });
    }

    private function evaluateProjection(User $actor, string $frontDeskStayId, string $propertyId): GuestLedgerCheckoutSettlementReadinessProjection
    {
        // ── Resolve Stay (non-disclosing) ─────────────────────────────────
        $stay = FrontDeskStay::withoutGlobalScope('property')
            ->where('id', $frontDeskStayId)
            ->where('property_id', $propertyId)
            ->first();
        if (! $stay) { throw new NotFoundException('FrontDeskStay'); }

        $evaluatedAt = now()->toIsoString();
        $blockers = []; $blockerMsgs = []; $reviews = []; $unavailable = [];
        $markers = []; $sourceIds = [];

        // ── Stay → Reservation → Guest integrity ──────────────────────────
        $reservation = Reservation::withoutGlobalScope('property')
            ->where('id', $stay->reservation_id)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation || $stay->reservation_id !== $reservation->id) {
            return $this->evidenceUnavailable($propertyId, $frontDeskStayId, '', '', [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                ['evaluated_at' => $evaluatedAt],
                'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE',
                'Stay-to-Reservation relationship could not be resolved.');
        }

        $guestId = $reservation->primary_guest_id ?? '';
        if (empty($guestId) || $stay->guest_id !== $guestId) {
            return $this->evidenceUnavailable($propertyId, $frontDeskStayId, $reservation->id, $guestId, [], null,
                ['stay_relationship_marker' => 'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE'],
                ['evaluated_at' => $evaluatedAt],
                'STAY_RESERVATION_LINK_EVIDENCE_UNAVAILABLE',
                'Stay guest does not match reservation primary guest or primary guest missing.');
        }

        $markers['stay_relationship_marker'] = 'STAY_RESERVATION_GUEST_RESOLVED';

        // ── Checkout-relevant Folios ──────────────────────────────────────
        $folios = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservation->id)
            ->orderBy('window_number')
            ->get();

        if ($folios->isEmpty()) {
            return $this->evidenceUnavailable($propertyId, $frontDeskStayId, $reservation->id, $guestId, [], null,
                ['folio_scope_marker' => 'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE'],
                ['evaluated_at' => $evaluatedAt],
                'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE',
                'No checkout-relevant folios found.');
        }

        $folioIds = []; $currencies = []; $aggregateBalance = '0.00';
        $allFolioFreshTotals = []; $allFolioCachedTotals = [];
        $allFolioItemIds = [];
        $allPaymentIds = []; $allAllocIds = []; $allPayRevIds = [];
        $allDepositIds = []; $allAppIds = []; $allDepRevIds = [];
        $allRefundIds = [];
        $allArRequestIds = []; $allArDecisionIds = [];

        // ── Per-Folio evaluation ──────────────────────────────────────────
        foreach ($folios as $folio) {
            $folioIds[] = $folio->id;
            $currencies[$folio->currency ?? ''] = true;

            if ($folio->guest_id !== $guestId) {
                $reviews[] = 'FOLIO_RELATIONSHIP_CONFLICT';
                $blockerMsgs[] = "Folio {$folio->id} guest mismatch.";
            }
            if ($folio->status === FolioStatusEnum::Closed || $folio->status === FolioStatusEnum::Void) {
                $reviews[] = 'FOLIO_LIFECYCLE_REVIEW_REQUIRED';
                $blockerMsgs[] = "Folio {$folio->id} is {$folio->status->value}.";
            }

            $activeItems = FolioItem::where('folio_id', $folio->id)
                ->where('is_void', false)->orderBy('posted_at')->get();
            $fresh = $this->calculator->calculate($activeItems);
            $allFolioFreshTotals[$folio->id] = $fresh;
            $allFolioCachedTotals[$folio->id] = [
                'total_charges' => (string) $folio->total_charges,
                'total_payments' => (string) $folio->total_payments,
                'total_deposits' => (string) $folio->total_deposits,
                'total_ar_transfers' => (string) $folio->total_ar_transfers,
                'balance' => (string) $folio->balance,
            ];

            // Compare all five fields; balance mismatch alone triggers review
            $mismatch = false;
            foreach (['total_charges','total_payments','total_deposits','total_ar_transfers','balance'] as $f) {
                if (bccomp($fresh[$f], $allFolioCachedTotals[$folio->id][$f], 2) !== 0) {
                    $mismatch = true;
                    break;
                }
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

            foreach ($activeItems as $item) {
                $allFolioItemIds[] = $item->id;
            }
        }

        $resolvedCurrency = count($currencies) === 1 ? key($currencies) : null;
        if (count($currencies) > 1) {
            $reviews[] = 'FOLIO_CURRENCY_CONFLICT';
            $blockerMsgs[] = 'Multiple currencies across folios.';
        }

        $markers['folio_scope_marker'] = count($folioIds) > 1 ? 'MULTI_FOLIO_EVALUATED' : 'SINGLE_FOLIO_EVALUATED';
        $markers['folio_totals_marker'] = 'FRESH_SOURCE_CALCULATED';

        // ── Guest Payments ────────────────────────────────────────────────
        $this->evaluatePayments($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews,
            $allPaymentIds, $allAllocIds, $allPayRevIds, $markers);

        // ── Guest Deposits ────────────────────────────────────────────────
        $this->evaluateDeposits($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews,
            $allDepositIds, $allAppIds, $allDepRevIds, $markers);

        // ── Guest Refunds ─────────────────────────────────────────────────
        $this->evaluateRefunds($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $blockers, $blockerMsgs, $reviews, $allRefundIds, $markers);

        // ── AR Transfers ──────────────────────────────────────────────────
        $this->evaluateArTransfers($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews,
            $allArRequestIds, $allArDecisionIds, $markers);

        // ── External ports ────────────────────────────────────────────────
        $this->evaluateExternalPorts($reservation->id, $propertyId,
            $blockers, $blockerMsgs, $reviews, $unavailable, $markers, $sourceIds);

        // ── Status ────────────────────────────────────────────────────────
        $status = $this->determineStatus($unavailable, $reviews, $blockers);

        // ── Source identifiers ────────────────────────────────────────────
        sort($allFolioItemIds); sort($allPaymentIds); sort($allAllocIds); sort($allPayRevIds);
        sort($allDepositIds); sort($allAppIds); sort($allDepRevIds);
        sort($allRefundIds); sort($allArRequestIds); sort($allArDecisionIds);
        $sourceIds['folio_ids'] = $folioIds;
        $sourceIds['folio_item_ids'] = $allFolioItemIds;
        $sourceIds['payment_ids'] = $allPaymentIds;
        $sourceIds['payment_allocation_ids'] = $allAllocIds;
        $sourceIds['payment_reversal_ids'] = $allPayRevIds;
        $sourceIds['deposit_ids'] = $allDepositIds;
        $sourceIds['deposit_application_ids'] = $allAppIds;
        $sourceIds['deposit_reversal_ids'] = $allDepRevIds;
        $sourceIds['refund_ids'] = $allRefundIds;
        $sourceIds['ar_request_ids'] = $allArRequestIds;
        $sourceIds['ar_decision_ids'] = $allArDecisionIds;
        $sourceIds['property_id'] = $propertyId;
        $sourceIds['front_desk_stay_id'] = $frontDeskStayId;
        $sourceIds['reservation_id'] = $reservation->id;
        $sourceIds['guest_id'] = $guestId;

        // ── Fingerprint ───────────────────────────────────────────────────
        $fingerprint = $this->buildFingerprint(
            $propertyId, $frontDeskStayId, $reservation->id, $guestId,
            $allFolioFreshTotals, $allFolioCachedTotals, $folioIds,
            $currencies, $resolvedCurrency, $status->value,
            $blockers, $reviews, $unavailable,
            $allPaymentIds, $allAllocIds, $allPayRevIds,
            $allDepositIds, $allAppIds, $allDepRevIds,
            $allRefundIds, $allArRequestIds, $allArDecisionIds,
            $allFolioItemIds,
        );

        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version: self::PROJECTION_VERSION,
            status: $status,
            property_id: $propertyId,
            front_desk_stay_id: $frontDeskStayId,
            reservation_id: $reservation->id,
            guest_id: $guestId,
            folio_ids: $folioIds,
            folio_count: count($folios),
            canonical_aggregate_balance: $aggregateBalance,
            currency: $resolvedCurrency,
            blocker_codes: $blockers,
            blocker_messages: $blockerMsgs,
            review_reasons: $reviews,
            evidence_unavailable_codes: $unavailable,
            markers: $markers,
            evaluated_at: $evaluatedAt,
            source_fingerprint: $fingerprint,
            source_identifiers: $sourceIds,
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Payment evaluation
    // ═════════════════════════════════════════════════════════════════════════

    private function evaluatePayments(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$allPaymentIds, array &$allAllocIds, array &$allPayRevIds, array &$markers
    ): void {
        $payments = GuestPaymentTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)->get();

        $allResolved = true;
        $anyPayment = false;

        foreach ($payments as $p) {
            $anyPayment = true;
            $allPaymentIds[] = $p->id;
            $pAmount = bcadd((string) $p->amount, '0.00', 2);

            if ($p->guest_id !== $guestId) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} guest mismatch.";
            }
            if ($currency !== null && $p->currency !== $currency) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} currency mismatch.";
            }

            // ── VOIDED ────────────────────────────────────────────────────
            if ($p->lifecycle_status === GuestPaymentLifecycleStatusEnum::Voided) {
                $voidRevs = GuestPaymentReversal::where('property_id', $propertyId)
                    ->where('guest_payment_transaction_id', $p->id)
                    ->where('reversal_type', GuestPaymentReversalTypeEnum::PaymentVoid->value)
                    ->get();
                $hasAlloc = GuestPaymentAllocation::where('property_id', $propertyId)
                    ->where('guest_payment_transaction_id', $p->id)->exists();
                $hasRefund = GuestRefundTransaction::where('property_id', $propertyId)
                    ->where('guest_payment_transaction_id', $p->id)->exists();

                if ($voidRevs->count() !== 1 || bccomp(bcadd((string) $voidRevs->first()->amount, '0.00', 2), $pAmount, 2) !== 0) {
                    $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Payment {$p->id} void evidence invalid.";
                }
                if ($hasAlloc || $hasRefund) {
                    $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Payment {$p->id} VOIDED but has allocation/refund.";
                }
                foreach ($voidRevs as $vr) { $allPayRevIds[] = $vr->id; }
                continue;
            }

            // ── Active allocations vs reversals ───────────────────────────
            $allocations = GuestPaymentAllocation::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $p->id)->get();
            $activeAllocated = '0.00';

            foreach ($allocations as $alloc) {
                $allAllocIds[] = $alloc->id;
                $rev = GuestPaymentReversal::where('property_id', $propertyId)
                    ->where('guest_payment_allocation_id', $alloc->id)
                    ->where('reversal_type', GuestPaymentReversalTypeEnum::AllocationReversal->value)
                    ->first();

                if ($rev) {
                    $allPayRevIds[] = $rev->id;
                    // Verify reversed allocation has preserved source FolioItems
                    $origItem = FolioItem::where('guest_payment_allocation_id', $alloc->id)
                        ->where('is_void', false)->first();
                    $revItem  = FolioItem::where('guest_payment_reversal_id', $rev->id)
                        ->where('is_void', false)->first();
                    if (! $origItem || ! $revItem
                        || bccomp((string) $revItem->amount, bcadd((string) $alloc->amount, '0.00', 2), 2) !== 0
                        || (string) $revItem->amount === (string) $origItem->amount // should be opposite sign
                    ) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Payment allocation {$alloc->id} reversal linkage invalid.";
                    }
                    if ($revItem && $revItem->reverses_folio_item_id !== $origItem->id) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Payment reversal {$rev->id} reverses_folio_item_id mismatch.";
                    }
                } else {
                    $activeAllocated = bcadd($activeAllocated, bcadd((string) $alloc->amount, '0.00', 2), 2);
                    // Verify exact one Payment FolioItem
                    $payItem = FolioItem::where('guest_payment_allocation_id', $alloc->id)
                        ->where('is_void', false)->first();
                    if (! $payItem
                        || $payItem->property_id !== $propertyId
                        || ! in_array($payItem->folio_id, $folioIds, true)
                        || $payItem->item_type !== FolioItemTypeEnum::Payment
                        || bccomp(bcadd((string) $payItem->amount, '0.00', 2), bcmul(bcadd((string) $alloc->amount, '0.00', 2), '-1', 2), 2) !== 0
                    ) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Payment allocation {$alloc->id} missing/invalid Payment FolioItem.";
                    }
                    if (! in_array($alloc->folio_id, $folioIds, true)) {
                        $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Payment allocation {$alloc->id} outside checkout folios.";
                    }
                }
            }

            $refundedTotal = '0.00';
            foreach (GuestRefundTransaction::where('property_id', $propertyId)
                ->where('guest_payment_transaction_id', $p->id)->get() as $ref) {
                $refundedTotal = bcadd($refundedTotal, bcadd((string) $ref->amount, '0.00', 2), 2);
            }

            $resolved = bcadd($activeAllocated, $refundedTotal, 2);
            $cmp = bccomp($resolved, $pAmount, 2);
            $allResolved = $allResolved && ($cmp === 0);

            if ($cmp > 0) {
                $reviews[] = 'PAYMENT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Payment {$p->id} over-resolved.";
            } elseif ($cmp < 0) {
                $blockers[] = 'GUEST_PAYMENT_UNRESOLVED';
                $allResolved = false;
                $blockerMsgs[] = "Payment {$p->id} unresolved " . bcsub($pAmount, $resolved, 2) . ".";
            }
        }

        $markers['payment_resolution_marker'] = (! $anyPayment || $allResolved)
            ? 'PAYMENT_RESOLUTION_COMPLETE' : 'PAYMENT_RESOLUTION_INCOMPLETE';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Deposit evaluation
    // ═════════════════════════════════════════════════════════════════════════

    private function evaluateDeposits(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$allDepositIds, array &$allAppIds, array &$allDepRevIds, array &$markers
    ): void {
        $deposits = GuestDepositTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)->get();

        $allResolved = true;
        $anyDeposit = false;

        foreach ($deposits as $d) {
            $anyDeposit = true;
            $allDepositIds[] = $d->id;
            $dAmount = bcadd((string) $d->amount, '0.00', 2);

            if ($d->guest_id !== $guestId) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} guest mismatch.";
            }
            if ($currency !== null && $d->currency !== $currency) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} currency mismatch.";
            }

            // ── VOIDED ────────────────────────────────────────────────────
            if ($d->lifecycle_status === GuestDepositLifecycleStatusEnum::Voided) {
                $voidRevs = GuestDepositReversal::where('property_id', $propertyId)
                    ->where('guest_deposit_transaction_id', $d->id)
                    ->where('reversal_type', GuestDepositReversalTypeEnum::DepositVoid->value)
                    ->get();
                $hasApp = GuestDepositApplication::where('property_id', $propertyId)
                    ->where('guest_deposit_transaction_id', $d->id)->exists();
                $hasRef = GuestRefundTransaction::where('property_id', $propertyId)
                    ->where('guest_deposit_transaction_id', $d->id)->exists();

                if ($voidRevs->count() !== 1 || bccomp(bcadd((string) $voidRevs->first()->amount, '0.00', 2), $dAmount, 2) !== 0) {
                    $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Deposit {$d->id} void evidence invalid.";
                }
                if ($hasApp || $hasRef) {
                    $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                    $blockerMsgs[] = "Deposit {$d->id} VOIDED but has application/refund.";
                }
                foreach ($voidRevs as $vr) { $allDepRevIds[] = $vr->id; }
                continue;
            }

            $applications = GuestDepositApplication::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $d->id)->get();
            $activeApplied = '0.00';

            foreach ($applications as $app) {
                $allAppIds[] = $app->id;
                $rev = GuestDepositReversal::where('property_id', $propertyId)
                    ->where('guest_deposit_application_id', $app->id)
                    ->where('reversal_type', GuestDepositReversalTypeEnum::ApplicationReversal->value)
                    ->first();

                if ($rev) {
                    $allDepRevIds[] = $rev->id;
                    $origItem = FolioItem::where('guest_deposit_application_id', $app->id)
                        ->where('is_void', false)->first();
                    $revItem  = FolioItem::where('guest_deposit_reversal_id', $rev->id)
                        ->where('is_void', false)->first();
                    if (! $origItem || ! $revItem
                        || bccomp((string) $revItem->amount, bcadd((string) $app->amount, '0.00', 2), 2) !== 0
                    ) {
                        $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Deposit application {$app->id} reversal linkage invalid.";
                    }
                    if ($revItem && $revItem->reverses_folio_item_id !== $origItem->id) {
                        $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Deposit reversal reverses_folio_item_id mismatch.";
                    }
                } else {
                    $activeApplied = bcadd($activeApplied, bcadd((string) $app->amount, '0.00', 2), 2);
                    $depItem = FolioItem::where('guest_deposit_application_id', $app->id)
                        ->where('is_void', false)->first();
                    if (! $depItem
                        || $depItem->property_id !== $propertyId
                        || ! in_array($depItem->folio_id, $folioIds, true)
                        || $depItem->item_type !== FolioItemTypeEnum::Deposit
                        || bccomp(bcadd((string) $depItem->amount, '0.00', 2), bcmul(bcadd((string) $app->amount, '0.00', 2), '-1', 2), 2) !== 0
                    ) {
                        $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                        $blockerMsgs[] = "Deposit application {$app->id} missing/invalid Deposit FolioItem.";
                    }
                }
            }

            $refundedTotal = '0.00';
            foreach (GuestRefundTransaction::where('property_id', $propertyId)
                ->where('guest_deposit_transaction_id', $d->id)->get() as $ref) {
                $refundedTotal = bcadd($refundedTotal, bcadd((string) $ref->amount, '0.00', 2), 2);
            }

            $resolved = bcadd($activeApplied, $refundedTotal, 2);
            $cmp = bccomp($resolved, $dAmount, 2);
            $allResolved = $allResolved && ($cmp === 0);

            if ($cmp > 0) {
                $reviews[] = 'DEPOSIT_SOURCE_CONFLICT';
                $blockerMsgs[] = "Deposit {$d->id} over-resolved.";
            } elseif ($cmp < 0) {
                $blockers[] = 'GUEST_DEPOSIT_UNRESOLVED';
                $allResolved = false;
                $blockerMsgs[] = "Deposit {$d->id} unresolved " . bcsub($dAmount, $resolved, 2) . ".";
            }
        }

        $markers['deposit_resolution_marker'] = (! $anyDeposit || $allResolved)
            ? 'DEPOSIT_RESOLUTION_COMPLETE' : 'DEPOSIT_RESOLUTION_INCOMPLETE';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Refund evaluation
    // ═════════════════════════════════════════════════════════════════════════

    private function evaluateRefunds(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$allRefundIds, array &$markers
    ): void {
        $refunds = GuestRefundTransaction::where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)->get();
        $anyIssue = false;

        foreach ($refunds as $r) {
            $allRefundIds[] = $r->id;

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

            $hasPay = ! empty($r->guest_payment_transaction_id);
            $hasDep = ! empty($r->guest_deposit_transaction_id);
            if ($hasPay === $hasDep) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} must have exactly one source."; $anyIssue = true; continue;
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

            if (bccomp(bcadd((string) $r->amount, '0.00', 2), '0.00', 2) <= 0) {
                $reviews[] = 'REFUND_SOURCE_CONFLICT';
                $blockerMsgs[] = "Refund {$r->id} amount not positive."; $anyIssue = true;
            }
        }

        $markers['refund_resolution_marker'] = $anyIssue
            ? 'REFUND_RESOLUTION_REVIEW_REQUIRED' : 'REFUND_RESOLUTION_TERMINAL';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AR Transfer evaluation
    // ═════════════════════════════════════════════════════════════════════════

    private function evaluateArTransfers(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$allArRequestIds, array &$allArDecisionIds, array &$markers
    ): void {
        $folioIdsForRes = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->pluck('id')->toArray();

        $requests = GuestArTransferRequest::where('property_id', $propertyId)
            ->whereIn('folio_id', $folioIdsForRes)->get();
        $anyBlock = false; $anyReview = false;

        foreach ($requests as $req) {
            $allArRequestIds[] = $req->id;
            if ($req->guest_id !== $guestId || ($currency !== null && $req->currency !== $currency)) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} guest/currency mismatch."; $anyReview = true;
            }
            if (! in_array($req->folio_id, $folioIds, true)) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} outside checkout folios."; $anyReview = true;
            }

            $decisions = GuestArTransferDecision::where('property_id', $propertyId)
                ->where('guest_ar_transfer_request_id', $req->id)
                ->orderBy('created_at')->get();
            foreach ($decisions as $dec) { $allArDecisionIds[] = $dec->id; }

            $accepted  = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Accepted);
            $rejected  = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Rejected);
            $reversed  = $decisions->where('decision_type', GuestArTransferDecisionTypeEnum::Reversed);

            match ($req->lifecycle_status) {
                GuestArTransferStatusEnum::Requested => $this->arRequested($req, $decisions, $blockers, $blockerMsgs, $reviews, $anyBlock, $anyReview),
                GuestArTransferStatusEnum::Accepted  => $this->arAccepted($req, $accepted, $rejected, $reversed, $propertyId, $folioIds, $blockers, $blockerMsgs, $reviews, $anyReview),
                GuestArTransferStatusEnum::Rejected  => $this->arRejected($req, $accepted, $rejected, $reviews, $blockerMsgs, $anyReview),
                GuestArTransferStatusEnum::Reversed  => $this->arReversed($req, $accepted, $reversed, $propertyId, $blockers, $blockerMsgs, $reviews, $anyReview),
            };

            // Conflicting decisions
            if ($accepted->count() > 0 && $rejected->count() > 0) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} has conflicting accepted+rejected."; $anyReview = true;
            }
            if ($accepted->count() > 1 && $req->lifecycle_status !== GuestArTransferStatusEnum::Reversed) {
                $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
                $blockerMsgs[] = "AR {$req->id} has multiple accepted."; $anyReview = true;
            }
        }

        $markers['ar_transfer_marker'] = $anyBlock ? 'AR_TRANSFER_BLOCKED'
            : ($anyReview ? 'AR_TRANSFER_REVIEW_REQUIRED' : 'AR_TRANSFER_CLEAR');
    }

    private function arRequested($req, $decisions, &$blockers, &$blockerMsgs, &$reviews, &$anyBlock, &$anyReview): void {
        $blockers[] = 'GUEST_AR_TRANSFER_PENDING';
        $blockerMsgs[] = "AR {$req->id} pending decision.";
        $anyBlock = true;
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
            $blockerMsgs[] = "AR {$req->id} ACCEPTED needs exactly 1 accepted decision."; $anyReview = true;
            return;
        }
        $acc = $accepted->first();
        $item = FolioItem::where('guest_ar_transfer_decision_id', $acc->id)
            ->where('is_void', false)->first();
        if (! $item
            || $item->property_id !== $propertyId
            || ! in_array($item->folio_id, $folioIds, true)
            || $item->item_type !== FolioItemTypeEnum::ArTransfer
            || bccomp((string) $item->amount, '0.00', 2) >= 0
        ) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} ACCEPTED missing/invalid ArTransfer FolioItem."; $anyReview = true;
        }
    }

    private function arRejected($req, $accepted, $rejected, &$reviews, &$blockerMsgs, &$anyReview): void {
        if ($accepted->isNotEmpty() || $rejected->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REJECTED decision conflict."; $anyReview = true;
        }
    }

    private function arReversed($req, $accepted, $reversed, $propertyId, &$blockers, &$blockerMsgs, &$reviews, &$anyReview): void {
        if ($accepted->count() !== 1 || $reversed->count() !== 1) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REVERSED needs 1 accepted + 1 reversed."; $anyReview = true;
            return;
        }
        $acc = $accepted->first();
        $rev = $reversed->first();
        if ($rev->reverses_decision_id !== $acc->id) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} reversal not linked to accepted."; $anyReview = true;
        }
        $origItem = FolioItem::where('guest_ar_transfer_decision_id', $acc->id)
            ->where('is_void', false)->first();
        $revItem  = FolioItem::where('guest_ar_transfer_decision_id', $rev->id)
            ->where('is_void', false)->first();
        if (! $origItem || ! $revItem
            || $origItem->item_type !== FolioItemTypeEnum::ArTransfer
            || $revItem->item_type !== FolioItemTypeEnum::ArTransferReversal
        ) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} REVERSED missing FolioItems."; $anyReview = true;
        }
        if ($revItem && $revItem->reverses_folio_item_id !== $origItem->id) {
            $reviews[] = 'AR_TRANSFER_SOURCE_CONFLICT';
            $blockerMsgs[] = "AR {$req->id} reversal FolioItem linkage invalid."; $anyReview = true;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // External ports
    // ═════════════════════════════════════════════════════════════════════════

    private function evaluateExternalPorts(
        string $reservationId, string $propertyId,
        array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$unavailable, array &$markers, array &$sourceIds
    ): void {
        foreach ([
            ['port' => $this->postingCompletenessPort, 'blocker' => 'MANDATORY_POSTINGS_INCOMPLETE',
             'review' => 'POSTING_COMPLETENESS_REVIEW_REQUIRED', 'marker' => 'posting_completeness_marker',
             'idKey' => 'posting_completeness'],
            ['port' => $this->settlementHoldPort, 'blocker' => 'SETTLEMENT_HOLD_ACTIVE',
             'review' => 'SETTLEMENT_HOLD_REVIEW_REQUIRED', 'marker' => 'settlement_hold_marker',
             'idKey' => 'settlement_hold'],
            ['port' => $this->completedSettlementPort, 'blocker' => 'CONFLICTING_COMPLETED_SETTLEMENT',
             'review' => 'COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED', 'marker' => 'completed_settlement_conflict_marker',
             'idKey' => 'completed_settlement_conflict'],
        ] as $cfg) {
            $result = $cfg['port']->evaluate($reservationId, $propertyId);
            $sourceIds[$cfg['idKey'] . '_status'] = $result['status'];
            $sourceIds[$cfg['idKey'] . '_code'] = $result['code'] ?? 'null';

            match ($result['status']) {
                'AVAILABLE_CLEAR' => $markers[$cfg['marker']] = strtoupper($cfg['idKey']) . '_CLEAR',
                'AVAILABLE_BLOCKED' => (function () use ($cfg, $result, &$blockers, &$blockerMsgs, &$markers) {
                    $blockers[] = $cfg['blocker'];
                    $blockerMsgs[] = $result['message'] ?? $cfg['blocker'];
                    $markers[$cfg['marker']] = strtoupper($cfg['idKey']) . '_BLOCKED';
                })(),
                'REVIEW_REQUIRED' => (function () use ($cfg, $result, &$reviews, &$blockerMsgs, &$markers) {
                    $reviews[] = $cfg['review'];
                    $blockerMsgs[] = $result['message'] ?? $cfg['review'];
                    $markers[$cfg['marker']] = strtoupper($cfg['idKey']) . '_REVIEW_REQUIRED';
                })(),
                default => (function () use ($cfg, $result, &$unavailable, &$blockerMsgs, &$markers) {
                    $unavailable[] = $result['code'] ?? strtoupper($cfg['idKey']) . '_EVIDENCE_UNAVAILABLE';
                    $blockerMsgs[] = $result['message'] ?? ($cfg['idKey'] . ' evidence unavailable.');
                    $markers[$cfg['marker']] = strtoupper($cfg['idKey']) . '_EVIDENCE_UNAVAILABLE';
                })(),
            };
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Status precedence
    // ═════════════════════════════════════════════════════════════════════════

    private function determineStatus(array $unavailable, array $reviews, array $blockers): GuestLedgerSettlementReadinessStatusEnum
    {
        if (! empty($unavailable)) return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable;
        if (! empty($reviews))    return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReviewRequired;
        if (! empty($blockers))   return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked;
        return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Source fingerprint — full canonical sorted source facts
    // ═════════════════════════════════════════════════════════════════════════

    private function buildFingerprint(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $freshTotals, array $cachedTotals, array $folioIds,
        array $currencies, ?string $resolvedCurrency, string $statusValue,
        array $blockers, array $reviews, array $unavailable,
        array $paymentIds, array $allocIds, array $payRevIds,
        array $depositIds, array $appIds, array $depRevIds,
        array $refundIds, array $arReqIds, array $arDecIds,
        array $folioItemIds,
    ): string {
        $parts = [];
        $parts[] = "p:{$propertyId}";
        $parts[] = "s:{$stayId}";
        $parts[] = "r:{$reservationId}";
        $parts[] = "g:{$guestId}";
        $parts[] = "cur:" . ($resolvedCurrency ?? 'null');

        // Fresh totals (source truth)
        foreach ($folioIds as $fid) {
            $ft = $freshTotals[$fid] ?? ['total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'];
            $ct = $cachedTotals[$fid] ?? $ft;
            $parts[] = "ff:{$fid}:{$ft['total_charges']}:{$ft['total_payments']}:{$ft['total_deposits']}:{$ft['total_ar_transfers']}:{$ft['balance']}";
            $parts[] = "fc:{$fid}:{$ct['total_charges']}:{$ct['total_payments']}:{$ct['total_deposits']}:{$ct['total_ar_transfers']}:{$ct['balance']}";
        }

        $s = function (array $a): string { sort($a); return implode(',', $a); };
        $parts[] = 'fi:' . $s($folioItemIds);
        $parts[] = 'pm:' . $s($paymentIds);
        $parts[] = 'pa:' . $s($allocIds);
        $parts[] = 'pr:' . $s($payRevIds);
        $parts[] = 'dp:' . $s($depositIds);
        $parts[] = 'da:' . $s($appIds);
        $parts[] = 'dr:' . $s($depRevIds);
        $parts[] = 'rf:' . $s($refundIds);
        $parts[] = 'arq:' . $s($arReqIds);
        $parts[] = 'ard:' . $s($arDecIds);
        $parts[] = 'bl:' . $s($blockers);
        $parts[] = 'rv:' . $s($reviews);
        $parts[] = 'un:' . $s($unavailable);

        return hash('sha256', implode('|', $parts));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Evidence unavailable helper
    // ═════════════════════════════════════════════════════════════════════════

    private function evidenceUnavailable(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $folioIds, ?string $currency, array $markers, array $sourceIds,
        string $code, string $message
    ): GuestLedgerCheckoutSettlementReadinessProjection {
        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version: self::PROJECTION_VERSION,
            status: GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
            property_id: $propertyId,
            front_desk_stay_id: $stayId,
            reservation_id: $reservationId,
            guest_id: $guestId,
            folio_ids: $folioIds,
            folio_count: count($folioIds),
            canonical_aggregate_balance: '0.00',
            currency: $currency,
            blocker_codes: [],
            blocker_messages: [$message],
            review_reasons: [],
            evidence_unavailable_codes: [$code],
            markers: $markers,
            evaluated_at: now()->toIsoString(),
            source_fingerprint: hash('sha256', "$code|$stayId|$propertyId"),
            source_identifiers: $sourceIds,
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Authorization
    // ═════════════════════════════════════════════════════════════════════════

    private function resolveCurrentProperty(): string
    {
        $id = session('active_property_id') ?? session('current_property_id')
            ?? $this->currentProperty->resolveOrFail();
        $this->currentProperty->setPropertyId($id);
        return $id;
    }

    private function guardActor(User $actor, string $propertyId): void
    {
        if (! auth()->check() || auth()->id() !== $actor->id) {
            throw new AuthorizationException('Actor identity does not match.');
        }
        $fresh = User::whereKey($actor->id)->where('is_active', true)->first();
        if (! $fresh) {
            throw new AuthorizationException('Active actor required.');
        }
        $has = $fresh->properties()->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')->exists();
        if (! $has) {
            throw new AuthorizationException('Active property membership required.');
        }
        try { $ok = $fresh->can(self::VIEW_PERMISSION); } catch (Throwable) { $ok = false; }
        if (! $ok) {
            throw new AuthorizationException('Permission required.');
        }
    }
}

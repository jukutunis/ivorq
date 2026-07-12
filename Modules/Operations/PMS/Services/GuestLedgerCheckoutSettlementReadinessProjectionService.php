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
    public const PROJECTION_VERSION = 'GLF-D-1.2';
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
        $stay = FrontDeskStay::withoutGlobalScope('property')
            ->where('id', $frontDeskStayId)->where('property_id', $propertyId)->first();
        if (! $stay) { throw new NotFoundException('FrontDeskStay'); }

        $evaluatedAt = now()->toIsoString();
        $blockers = []; $blockerMsgs = []; $reviews = []; $unavailable = [];
        $markers = []; $sourceIds = [];
        $fingerprintFacts = []; // canonical sorted source-fact arrays for fingerprint

        // ── Stay → Reservation → Guest ──────────────────────────────────
        $reservation = Reservation::withoutGlobalScope('property')
            ->where('id', $stay->reservation_id)->where('property_id', $propertyId)->first();
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
                'Stay guest does not match reservation primary guest.');
        }
        $markers['stay_relationship_marker'] = 'STAY_RESERVATION_GUEST_RESOLVED';
        $fingerprintFacts['stay'] = ['id' => $stay->id, 'status' => $stay->status?->value ?? '', 'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id];

        // ── Folios ──────────────────────────────────────────────────────
        $folios = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)->where('reservation_id', $reservation->id)
            ->orderBy('window_number')->get();
        if ($folios->isEmpty()) {
            return $this->evidenceUnavailable($propertyId, $frontDeskStayId, $reservation->id, $guestId, [], null,
                ['folio_scope_marker' => 'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE'],
                ['evaluated_at' => $evaluatedAt],
                'CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE', 'No checkout-relevant folios found.');
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

            $activeItems = FolioItem::where('folio_id', $folio->id)->where('is_void', false)->orderBy('posted_at')->get();
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

            // Folio facts for fingerprint
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
            usort($itemFacts, fn($a,$b) => $a['id'] <=> $b['id']);
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

        // ── Payments ────────────────────────────────────────────────────
        $paymentFacts = $this->evaluatePayments($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews, $markers);

        // ── Deposits ────────────────────────────────────────────────────
        $depositFacts = $this->evaluateDeposits($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews, $markers);

        // ── Refunds ─────────────────────────────────────────────────────
        $refundFacts = $this->evaluateRefunds($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $blockers, $blockerMsgs, $reviews, $markers);

        // ── AR Transfers ────────────────────────────────────────────────
        $arFacts = $this->evaluateArTransfers($reservation->id, $propertyId, $guestId, $resolvedCurrency,
            $folioIds, $blockers, $blockerMsgs, $reviews, $markers);

        // ── External ports ──────────────────────────────────────────────
        $portFacts = $this->evaluateExternalPorts($reservation->id, $propertyId,
            $blockers, $blockerMsgs, $reviews, $unavailable, $markers, $sourceIds);

        // ── Status ──────────────────────────────────────────────────────
        $status = $this->determineStatus($unavailable, $reviews, $blockers);

        // ── Source identifiers ──────────────────────────────────────────
        $this->populateSourceIdentifiers($sourceIds, $fingerprintFacts, $folioIds, $paymentFacts,
            $depositFacts, $refundFacts, $arFacts, $folioFacts,
            $propertyId, $frontDeskStayId, $reservation->id, $guestId);

        // ── Fingerprint ─────────────────────────────────────────────────
        $fingerprint = $this->buildFingerprint(
            $propertyId, $stay->id, $reservation->id, $guestId,
            $folioFacts, $paymentFacts, $depositFacts, $refundFacts, $arFacts, $portFacts,
            $resolvedCurrency, $status->value
        );

        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version: self::PROJECTION_VERSION, status: $status,
            property_id: $propertyId, front_desk_stay_id: $frontDeskStayId,
            reservation_id: $reservation->id, guest_id: $guestId,
            folio_ids: $folioIds, folio_count: count($folios),
            canonical_aggregate_balance: $aggregateBalance, currency: $resolvedCurrency,
            blocker_codes: $blockers, blocker_messages: $blockerMsgs,
            review_reasons: $reviews, evidence_unavailable_codes: $unavailable,
            markers: $markers, evaluated_at: $evaluatedAt,
            source_fingerprint: $fingerprint, source_identifiers: $sourceIds,
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // PAYMENTS — exact cardinality, lifecycle consistency
    // ═════════════════════════════════════════════════════════════════════

    private function evaluatePayments(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers
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

            // ── VOIDED ──────────────────────────────────────────────────
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
                $facts[] = ['id' => $p->id, 'lifecycle' => $p->lifecycle_status->value, 'amount' => $pAmount,
                    'void_rev_count' => $voidRevs->count(), 'alloc_count' => $allocations->count(),
                    'refund_count' => $refunds->count()];
                continue;
            }

            // ── Allocations vs reversals ─────────────────────────────────
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
                    // Active allocation — exact 1 FolioItem
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

            // Lifecycle consistency
            $expectedLc = match (true) {
                bccomp($resolved, '0.00', 2) === 0 => GuestPaymentLifecycleStatusEnum::Recorded,
                bccomp($resolved, $pAmount, 2) < 0 => GuestPaymentLifecycleStatusEnum::PartiallyAllocated,
                bccomp($resolved, $pAmount, 2) === 0 => GuestPaymentLifecycleStatusEnum::FullyAllocated,
                default => null, // over-resolved → review below
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

            $facts[] = ['id' => $p->id, 'lifecycle' => $p->lifecycle_status->value, 'amount' => $pAmount,
                'resolved' => $resolved, 'active_alloc' => $activeAllocated, 'refunded' => $refundedTotal,
                'allocations' => $allocFacts];
        }

        $markers['payment_resolution_marker'] = (! $anyPayment || $allResolved)
            ? 'PAYMENT_RESOLUTION_COMPLETE' : 'PAYMENT_RESOLUTION_INCOMPLETE';
        return $facts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // DEPOSITS — exact cardinality, lifecycle consistency
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateDeposits(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array $folioIds, array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers
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

            // ── VOIDED ──────────────────────────────────────────────────
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
                $facts[] = ['id' => $d->id, 'lifecycle' => $d->lifecycle_status->value, 'amount' => $dAmount,
                    'void_rev_count' => $voidRevs->count(), 'app_count' => $applications->count(),
                    'refund_count' => $refunds->count()];
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

            // Lifecycle consistency
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

            $facts[] = ['id' => $d->id, 'lifecycle' => $d->lifecycle_status->value, 'amount' => $dAmount,
                'resolved' => $resolved, 'applications' => $appFacts];
        }

        $markers['deposit_resolution_marker'] = (! $anyDeposit || $allResolved)
            ? 'DEPOSIT_RESOLUTION_COMPLETE' : 'DEPOSIT_RESOLUTION_INCOMPLETE';
        return $facts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // REFUNDS — source-type agreement, exact source validation
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateRefunds(
        string $reservationId, string $propertyId, string $guestId, ?string $currency,
        array &$blockers, array &$blockerMsgs, array &$reviews, array &$markers
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

            // source-type agreement
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

            // Verify source row existence and agreement
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

            $facts[] = ['id' => $r->id, 'source_type' => $srcType, 'amount' => $rAmount,
                'payment_id' => $r->guest_payment_transaction_id ?? '',
                'deposit_id' => $r->guest_deposit_transaction_id ?? '',
                'currency' => $r->currency, 'guest_id' => $r->guest_id];
        }

        $markers['refund_resolution_marker'] = $anyIssue
            ? 'REFUND_RESOLUTION_REVIEW_REQUIRED' : 'REFUND_RESOLUTION_TERMINAL';
        return $facts;
    }

    // ═════════════════════════════════════════════════════════════════════
    // AR TRANSFERS — exact cardinality
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
    // External ports
    // ═════════════════════════════════════════════════════════════════════

    private function evaluateExternalPorts(
        string $reservationId, string $propertyId,
        array &$blockers, array &$blockerMsgs, array &$reviews,
        array &$unavailable, array &$markers, array &$sourceIds
    ): array {
        $portFacts = [];
        foreach ([
            ['port' => $this->postingCompletenessPort, 'blocker' => 'MANDATORY_POSTINGS_INCOMPLETE',
             'review' => 'POSTING_COMPLETENESS_REVIEW_REQUIRED', 'marker' => 'posting_completeness_marker',
             'key' => 'posting_completeness'],
            ['port' => $this->settlementHoldPort, 'blocker' => 'SETTLEMENT_HOLD_ACTIVE',
             'review' => 'SETTLEMENT_HOLD_REVIEW_REQUIRED', 'marker' => 'settlement_hold_marker',
             'key' => 'settlement_hold'],
            ['port' => $this->completedSettlementPort, 'blocker' => 'CONFLICTING_COMPLETED_SETTLEMENT',
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
    // Source identifiers
    // ═════════════════════════════════════════════════════════════════════

    private function populateSourceIdentifiers(
        array &$sourceIds, array &$ff, array $folioIds,
        array $paymentFacts, array $depositFacts, array $refundFacts, array $arFacts,
        array $folioFacts, string $propertyId, string $stayId, string $reservationId, string $guestId
    ): void {
        $sourceIds['property_id'] = $propertyId;
        $sourceIds['front_desk_stay_id'] = $stayId;
        $sourceIds['reservation_id'] = $reservationId;
        $sourceIds['guest_id'] = $guestId;
        $sourceIds['folio_ids'] = $folioIds;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Status
    // ═════════════════════════════════════════════════════════════════════

    private function determineStatus(array $unavailable, array $reviews, array $blockers): GuestLedgerSettlementReadinessStatusEnum
    {
        if (! empty($unavailable)) return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable;
        if (! empty($reviews))    return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReviewRequired;
        if (! empty($blockers))   return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked;
        return GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Fingerprint — full source facts, not just IDs
    // ═════════════════════════════════════════════════════════════════════

    private function buildFingerprint(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $folioFacts, array $paymentFacts, array $depositFacts, array $refundFacts,
        array $arFacts, array $portFacts, ?string $currency, string $statusValue
    ): string {
        $canonical = [];

        // Top-level identities
        $top = ['p' => $propertyId, 's' => $stayId, 'r' => $reservationId, 'g' => $guestId,
                'cur' => $currency ?? 'null', 'st' => $statusValue];
        ksort($top);
        $canonical[] = json_encode($top, JSON_UNESCAPED_SLASHES);

        // Folios with fresh + cached totals and items
        $ffKeys = array_keys($folioFacts); sort($ffKeys);
        foreach ($ffKeys as $fid) {
            $f = $folioFacts[$fid];
            $canonical[] = json_encode([
                'fid' => $fid, 'status' => $f['status'], 'currency' => $f['currency'],
                'fresh' => $f['fresh'], 'cached' => $f['cached'],
                'items' => $f['items'],
            ], JSON_UNESCAPED_SLASHES);
        }

        // Payments with allocations
        usort($paymentFacts, fn($a,$b) => ($a['id']??'') <=> ($b['id']??''));
        $canonical[] = json_encode($paymentFacts, JSON_UNESCAPED_SLASHES);

        // Deposits with applications
        usort($depositFacts, fn($a,$b) => ($a['id']??'') <=> ($b['id']??''));
        $canonical[] = json_encode($depositFacts, JSON_UNESCAPED_SLASHES);

        // Refunds
        usort($refundFacts, fn($a,$b) => ($a['id']??'') <=> ($b['id']??''));
        $canonical[] = json_encode($refundFacts, JSON_UNESCAPED_SLASHES);

        // AR transfers
        usort($arFacts, fn($a,$b) => ($a['id']??'') <=> ($b['id']??''));
        $canonical[] = json_encode($arFacts, JSON_UNESCAPED_SLASHES);

        // External ports
        $pfKeys = array_keys($portFacts); sort($pfKeys);
        $pfSorted = []; foreach ($pfKeys as $k) { $pfSorted[$k] = $portFacts[$k]; }
        $canonical[] = json_encode($pfSorted, JSON_UNESCAPED_SLASHES);

        return hash('sha256', implode('|', $canonical));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Evidence unavailable helper
    // ═════════════════════════════════════════════════════════════════════

    private function evidenceUnavailable(
        string $propertyId, string $stayId, string $reservationId, string $guestId,
        array $folioIds, ?string $currency, array $markers, array $sourceIds,
        string $code, string $message
    ): GuestLedgerCheckoutSettlementReadinessProjection {
        return GuestLedgerCheckoutSettlementReadinessProjection::create(
            projection_version: self::PROJECTION_VERSION,
            status: GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
            property_id: $propertyId, front_desk_stay_id: $stayId,
            reservation_id: $reservationId, guest_id: $guestId,
            folio_ids: $folioIds, folio_count: count($folioIds),
            canonical_aggregate_balance: '0.00', currency: $currency,
            blocker_codes: [], blocker_messages: [$message],
            review_reasons: [], evidence_unavailable_codes: [$code],
            markers: $markers, evaluated_at: now()->toIsoString(),
            source_fingerprint: hash('sha256', "$code|$stayId|$propertyId"),
            source_identifiers: $sourceIds,
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Authorization
    // ═════════════════════════════════════════════════════════════════════

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
        if (! $fresh) { throw new AuthorizationException('Active actor required.'); }
        $has = $fresh->properties()->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')->exists();
        if (! $has) { throw new AuthorizationException('Active property membership required.'); }
        try { $ok = $fresh->can(self::VIEW_PERMISSION); } catch (Throwable) { $ok = false; }
        if (! $ok) { throw new AuthorizationException('Permission required.'); }
    }
}

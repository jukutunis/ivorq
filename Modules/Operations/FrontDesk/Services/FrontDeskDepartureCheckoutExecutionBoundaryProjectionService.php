<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutObligationStatusEnum;
use Modules\Operations\PMS\Enums\GuestLedgerSettlementReadinessStatusEnum;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class FrontDeskDepartureCheckoutExecutionBoundaryProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.checkout-execution-boundary.view';

    // Stable blocker codes
    public const BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE = 'FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_FINANCIAL_SETTLEMENT_BLOCKED = 'FINANCIAL_SETTLEMENT_BLOCKED';
    public const BLOCKER_FINANCIAL_SETTLEMENT_REVIEW_REQUIRED = 'FINANCIAL_SETTLEMENT_REVIEW_REQUIRED';
    public const BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE = 'CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_CASHIER_OBLIGATION_BLOCKED = 'CASHIER_OBLIGATION_BLOCKED';
    public const BLOCKER_CASHIER_OBLIGATION_REVIEW_REQUIRED = 'CASHIER_OBLIGATION_REVIEW_REQUIRED';
    public const BLOCKER_BUSINESS_DATE_UNAVAILABLE = 'BUSINESS_DATE_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_NIGHT_AUDIT_LOCK_UNAVAILABLE = 'NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_FD_B7_NOT_READY = 'FD_B7_NOT_READY';
    public const BLOCKER_FD_B7_EVIDENCE_MISSING = 'FD_B7_EVIDENCE_MISSING';
    public const BLOCKER_STAY_NOT_IN_HOUSE = 'STAY_NOT_IN_HOUSE';
    public const BLOCKER_CHECKOUT_NOT_IMPLEMENTED = 'CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED';
    public const UNKNOWN_GUEST_LEDGER_SETTLEMENT_STATUS = 'FD_B9_UNKNOWN_GUEST_LEDGER_SETTLEMENT_STATUS';
    public const UNKNOWN_GENERAL_CASHIER_OBLIGATION_STATUS = 'FD_B10_UNKNOWN_GENERAL_CASHIER_OBLIGATION_STATUS';
    private const AUTHORIZATION_FAILURE_MESSAGE = 'Front Desk checkout execution boundary view is not authorized.';

    public function __construct(
        private readonly FrontDeskGuestLedgerSettlementReadinessDependencyService $guestLedgerSettlementReadiness,
        private readonly FrontDeskGeneralCashierCheckoutObligationDependencyService $generalCashierCheckoutObligation
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function boundary(User $actor, string $frontDeskStayId): array
    {
        $propertyId = $this->authorizeView($actor);

        // Resolve stay by ID + property only — do not filter by lifecycle status
        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($frontDeskStayId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $stay) {
            throw new HttpException(404, 'Front Desk stay not found.');
        }

        $stayIsInHouse = $stay->status === FrontDeskStayStatusEnum::InHouse;

        $blockerCodes = [];
        $blockerMessages = [];
        $reviewReasons = [];
        $authoritativeGates = [];

        // Gate 1: Stay is IN_HOUSE
        if ($stayIsInHouse) {
            $authoritativeGates['stay_in_house'] = [
                'gate' => 'Stay is IN_HOUSE',
                'owner' => 'Front Desk',
                'satisfied' => true,
                'detail' => 'Stay status is IN_HOUSE.',
            ];
        } else {
            $blockerCodes[] = self::BLOCKER_STAY_NOT_IN_HOUSE;
            $blockerMessages[] = 'Stay status is ' . ($stay->status?->value ?? 'unknown') . '. Checkout execution requires IN_HOUSE status.';
            $authoritativeGates['stay_in_house'] = [
                'gate' => 'Stay is IN_HOUSE',
                'owner' => 'Front Desk',
                'satisfied' => false,
                'detail' => 'Stay status is ' . ($stay->status?->value ?? 'unknown') . '.',
            ];
        }

        // Gate 2: Stay belongs to current property (verified by query)
        $authoritativeGates['property_ownership'] = [
            'gate' => 'Stay belongs to current property',
            'owner' => 'Front Desk',
            'satisfied' => true,
            'detail' => 'Stay property_id matches active property.',
        ];

        // Gate 3: Latest FD-B7 final review evidence
        $latestB7 = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        $latestFinalReviewStatus = null;
        $latestFinalReviewId = null;
        $latestFinalReviewCreatedAt = null;

        if ($latestB7) {
            $latestFinalReviewStatus = $latestB7->final_review_status?->value;
            $latestFinalReviewId = $latestB7->id;
            $latestFinalReviewCreatedAt = $latestB7->occurred_at?->toISOString();

            if ($latestFinalReviewStatus === 'CHECKOUT_FINAL_REVIEW_READY') {
                $authoritativeGates['fd_b7_final_review'] = [
                    'gate' => 'Latest FD-B7 final review is CHECKOUT_FINAL_REVIEW_READY',
                    'owner' => 'Front Desk',
                    'satisfied' => true,
                    'detail' => 'FD-B7 final review READY at ' . $latestFinalReviewCreatedAt,
                ];
            } elseif ($latestFinalReviewStatus === 'CHECKOUT_FINAL_REVIEW_REVIEWED') {
                $blockerCodes[] = self::BLOCKER_FD_B7_NOT_READY;
                $blockerMessages[] = 'Latest FD-B7 final review is CHECKOUT_FINAL_REVIEW_REVIEWED. CHECKOUT_FINAL_REVIEW_READY is required before checkout execution.';
                $reviewReasons[] = 'FD-B7 final review was marked REVIEWED. A new CHECKOUT_FINAL_REVIEW_READY entry is required before checkout execution.';
                $authoritativeGates['fd_b7_final_review'] = [
                    'gate' => 'Latest FD-B7 final review is CHECKOUT_FINAL_REVIEW_READY',
                    'owner' => 'Front Desk',
                    'satisfied' => false,
                    'detail' => 'Latest FD-B7 status: CHECKOUT_FINAL_REVIEW_REVIEWED.',
                ];
            } else {
                $blockerCodes[] = self::BLOCKER_FD_B7_NOT_READY;
                $blockerMessages[] = 'Latest FD-B7 final review is ' . $latestFinalReviewStatus . '. CHECKOUT_FINAL_REVIEW_READY is required before checkout execution.';
                $authoritativeGates['fd_b7_final_review'] = [
                    'gate' => 'Latest FD-B7 final review is CHECKOUT_FINAL_REVIEW_READY',
                    'owner' => 'Front Desk',
                    'satisfied' => false,
                    'detail' => 'Latest FD-B7 status: ' . $latestFinalReviewStatus,
                ];
            }
        } else {
            $blockerCodes[] = self::BLOCKER_FD_B7_EVIDENCE_MISSING;
            $blockerMessages[] = 'No FD-B7 checkout final review evidence exists. At least one CHECKOUT_FINAL_REVIEW_READY entry is required.';
            $authoritativeGates['fd_b7_final_review'] = [
                'gate' => 'Latest FD-B7 final review is CHECKOUT_FINAL_REVIEW_READY',
                'owner' => 'Front Desk',
                'satisfied' => false,
                'detail' => 'No FD-B7 evidence found.',
            ];
        }

        // Gate 4: Financial settlement evidence (PMS Guest Ledger GLF-D read-only dependency)
        $guestLedger = $this->guestLedgerSettlementReadiness->project($actor, $frontDeskStayId);
        $this->applyGuestLedgerSettlementReadiness($guestLedger, $blockerCodes, $blockerMessages, $reviewReasons);
        $authoritativeGates['financial_settlement'] = [
            'gate' => 'Folio balance is settled or transferred through an approved authoritative process',
            'owner' => 'PMS Guest Ledger',
            'satisfied' => $guestLedger['status'] === 'GUEST_LEDGER_SETTLEMENT_READY',
            'detail' => 'GLF-D settlement status: ' . $guestLedger['status'] . '.',
            'status' => $guestLedger['status'],
            'canonical_aggregate_balance' => $guestLedger['canonical_aggregate_balance'],
            'currency' => $guestLedger['currency'],
            'folio_count' => $guestLedger['folio_count'],
            'evaluated_at' => $guestLedger['evaluated_at'],
            'source_fingerprint' => $guestLedger['source_fingerprint'],
        ];

        // Gate 5: Cashier obligation evidence (General Cashier GC-A1 read-only dependency)
        $cashierObligation = $this->generalCashierCheckoutObligation->project($actor, $frontDeskStayId);
        $cashierGateSatisfied = $this->applyGeneralCashierCheckoutObligation($cashierObligation, $blockerCodes, $blockerMessages, $reviewReasons);
        $authoritativeGates['cashier_obligation'] = [
            'gate' => 'No unresolved cashier accountability obligation',
            'owner' => 'General Cashier',
            'satisfied' => $cashierGateSatisfied,
            'detail' => 'GC-A1 checkout obligation status: ' . $cashierObligation['status'] . '.',
            'status' => $cashierObligation['status'],
            'related_cashier_session_ids' => $cashierObligation['related_cashier_session_ids'],
            'related_cashier_session_count' => count($cashierObligation['related_cashier_session_ids'] ?? []),
            'related_guest_payment_transaction_ids' => $cashierObligation['related_guest_payment_transaction_ids'],
            'related_guest_payment_transaction_count' => count($cashierObligation['related_guest_payment_transaction_ids'] ?? []),
            'evaluated_at' => $cashierObligation['evaluated_at'],
            'source_fingerprint' => $cashierObligation['source_fingerprint'],
            'markers' => $cashierObligation['markers'],
        ];

        // Gate 6: Business date evidence (MISSING — ADR-034 Proposed, no implementation)
        $blockerCodes[] = self::BLOCKER_BUSINESS_DATE_UNAVAILABLE;
        $blockerMessages[] = 'Authoritative business date evidence is not available. Business date lifecycle is governed by ADR-034 (Proposed). No implementation exists.';
        $authoritativeGates['business_date'] = [
            'gate' => 'Business date permits checkout',
            'owner' => 'Business Date / Night Audit (ADR-034)',
            'satisfied' => false,
            'detail' => 'ADR-034 is Proposed. No business date implementation exists.',
        ];

        // Gate 7: Night Audit close-lock evidence (MISSING — ADR-034 Proposed, no implementation)
        $blockerCodes[] = self::BLOCKER_NIGHT_AUDIT_LOCK_UNAVAILABLE;
        $blockerMessages[] = 'Authoritative Night Audit close-lock evidence is not available. Night Audit lifecycle is governed by ADR-034 (Proposed). No implementation exists.';
        $authoritativeGates['night_audit_lock'] = [
            'gate' => 'No active Night Audit close lock',
            'owner' => 'Night Audit (ADR-034)',
            'satisfied' => false,
            'detail' => 'ADR-034 is Proposed. No Night Audit implementation exists.',
        ];

        // Gate 8: No existing completed checkout execution (not yet implemented)
        $blockerCodes[] = self::BLOCKER_CHECKOUT_NOT_IMPLEMENTED;
        $blockerMessages[] = 'Checkout execution has not yet been implemented. No checkout execution evidence can exist until a future checkout execution package is delivered.';
        $authoritativeGates['checkout_execution'] = [
            'gate' => 'No existing completed checkout execution',
            'owner' => 'Front Desk (future)',
            'satisfied' => false,
            'detail' => 'Checkout execution package has not been implemented.',
        ];

        $blockerCodes = $this->sortedUnique($blockerCodes);
        $blockerMessages = $this->sortedUnique($blockerMessages);
        $reviewReasons = $this->sortedUnique($reviewReasons);

        // Determine overall status. FD-B10 never executes checkout.
        $canExecute = false;
        if (empty($blockerCodes)) {
            $overallStatus = FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum::ExecutionBoundaryReady->value;
        } elseif (! empty($reviewReasons)) {
            $overallStatus = FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum::ExecutionBoundaryReviewRequired->value;
        } else {
            $overallStatus = FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum::ExecutionBoundaryBlocked->value;
        }

        return [
            'front_desk_stay_id' => $frontDeskStayId,
            'property_id' => $propertyId,
            'stay_status' => $stay->status?->value,
            'latest_final_review_status' => $latestFinalReviewStatus,
            'latest_final_review_id' => $latestFinalReviewId,
            'latest_final_review_created_at' => $latestFinalReviewCreatedAt,
            'execution_boundary_status' => $overallStatus,
            'can_execute' => $canExecute,
            'blocker_codes' => $blockerCodes,
            'blocker_messages' => $blockerMessages,
            'review_reasons' => $reviewReasons,
            'authoritative_gates' => $authoritativeGates,
            'guest_ledger_settlement_readiness' => $guestLedger,
            'general_cashier_checkout_obligation' => $cashierObligation,
            'execution_not_performed_marker' => 'Checkout execution is not performed in FD-B10.',
            'financial_settlement_marker' => 'Financial settlement readiness is evaluated read-only by PMS Guest Ledger GLF-D. Front Desk does not own or mutate Folios, payments, deposits, refunds, or AR transfers.',
            'cashier_obligation_marker' => 'Cashier obligation readiness is evaluated read-only by General Cashier GC-A1. Front Desk does not own or mutate cashier sessions, guest cash transactions, counts, handovers, reconciliation, or accountability completion.',
            'evaluated_at' => now()->toISOString(),
        ];
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

        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        if (! is_string($propertyId) || trim($propertyId) === '') {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('company_id', $company->id)
            ->where('is_active', true)
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

        if (! $this->actorCan($fresh, self::VIEW_PERMISSION)
            || ! $this->actorCan($fresh, FrontDeskGuestLedgerSettlementReadinessDependencyService::VIEW_PERMISSION)
            || ! $this->actorCan($fresh, FrontDeskGeneralCashierCheckoutObligationDependencyService::VIEW_PERMISSION)) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE_MESSAGE);
        }

        return $propertyId;
    }

    /**
     * @param array<string, mixed> $guestLedger
     * @param string[] $blockerCodes
     * @param string[] $blockerMessages
     * @param string[] $reviewReasons
     */
    private function applyGuestLedgerSettlementReadiness(
        array $guestLedger,
        array &$blockerCodes,
        array &$blockerMessages,
        array &$reviewReasons
    ): void {
        match ($guestLedger['status']) {
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady->value => null,
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked->value => $this->appendFinancialBlocker(
                $blockerCodes,
                $blockerMessages,
                self::BLOCKER_FINANCIAL_SETTLEMENT_BLOCKED,
                'PMS Guest Ledger GLF-D reports financial settlement blocked.'
            ),
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReviewRequired->value => $this->appendFinancialReview(
                $blockerCodes,
                $blockerMessages,
                $reviewReasons,
                $guestLedger['review_reasons'] ?? []
            ),
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable->value => $this->appendFinancialBlocker(
                $blockerCodes,
                $blockerMessages,
                self::BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE,
                'PMS Guest Ledger GLF-D settlement evidence is unavailable.'
            ),
            default => throw new DomainException(self::UNKNOWN_GUEST_LEDGER_SETTLEMENT_STATUS),
        };
    }

    /**
     * @param array<string, mixed> $cashierObligation
     * @param string[] $blockerCodes
     * @param string[] $blockerMessages
     * @param string[] $reviewReasons
     */
    private function applyGeneralCashierCheckoutObligation(
        array $cashierObligation,
        array &$blockerCodes,
        array &$blockerMessages,
        array &$reviewReasons
    ): bool {
        return match ($cashierObligation['status']) {
            GeneralCashierCheckoutObligationStatusEnum::CashierObligationClear->value => true,
            GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked->value => $this->appendCashierBlocker(
                $blockerCodes,
                $blockerMessages,
                self::BLOCKER_CASHIER_OBLIGATION_BLOCKED,
                'General Cashier GC-A1 reports cashier obligation blocked.'
            ),
            GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired->value => $this->appendCashierReview(
                $blockerCodes,
                $blockerMessages,
                $reviewReasons,
                $cashierObligation['review_reasons'] ?? []
            ),
            GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable->value => $this->appendCashierBlocker(
                $blockerCodes,
                $blockerMessages,
                self::BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE,
                'General Cashier GC-A1 cashier obligation evidence is unavailable.'
            ),
            default => throw new DomainException(self::UNKNOWN_GENERAL_CASHIER_OBLIGATION_STATUS),
        };
    }

    private function actorCan(User $actor, string $permission): bool
    {
        try {
            return $actor->can($permission);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param string[] $blockerCodes
     * @param string[] $blockerMessages
     */
    private function appendFinancialBlocker(array &$blockerCodes, array &$blockerMessages, string $code, string $message): void
    {
        $blockerCodes[] = $code;
        $blockerMessages[] = $message;
    }

    /**
     * @param string[] $blockerCodes
     * @param string[] $blockerMessages
     * @param string[] $reviewReasons
     * @param string[] $sourceReviewReasons
     */
    private function appendFinancialReview(
        array &$blockerCodes,
        array &$blockerMessages,
        array &$reviewReasons,
        array $sourceReviewReasons
    ): void {
        $blockerCodes[] = self::BLOCKER_FINANCIAL_SETTLEMENT_REVIEW_REQUIRED;
        $blockerMessages[] = 'PMS Guest Ledger GLF-D reports financial settlement requires review.';
        foreach ($sourceReviewReasons as $reason) {
            $reviewReasons[] = $reason;
        }
    }

    /**
     * @param string[] $blockerCodes
     * @param string[] $blockerMessages
     */
    private function appendCashierBlocker(array &$blockerCodes, array &$blockerMessages, string $code, string $message): bool
    {
        $blockerCodes[] = $code;
        $blockerMessages[] = $message;

        return false;
    }

    /**
     * @param string[] $blockerCodes
     * @param string[] $blockerMessages
     * @param string[] $reviewReasons
     * @param string[] $sourceReviewReasons
     */
    private function appendCashierReview(
        array &$blockerCodes,
        array &$blockerMessages,
        array &$reviewReasons,
        array $sourceReviewReasons
    ): bool {
        $blockerCodes[] = self::BLOCKER_CASHIER_OBLIGATION_REVIEW_REQUIRED;
        $blockerMessages[] = 'General Cashier GC-A1 reports cashier obligation requires review.';
        foreach ($sourceReviewReasons as $reason) {
            $reviewReasons[] = $reason;
        }

        return false;
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}

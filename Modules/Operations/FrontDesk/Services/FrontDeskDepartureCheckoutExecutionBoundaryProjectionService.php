<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutExecutionBoundaryProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.checkout-execution-boundary.view';

    // Stable blocker codes
    public const BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE = 'FINANCIAL_SETTLEMENT_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE = 'CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_BUSINESS_DATE_UNAVAILABLE = 'BUSINESS_DATE_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_NIGHT_AUDIT_LOCK_UNAVAILABLE = 'NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE';
    public const BLOCKER_FD_B7_NOT_READY = 'FD_B7_NOT_READY';
    public const BLOCKER_FD_B7_EVIDENCE_MISSING = 'FD_B7_EVIDENCE_MISSING';
    public const BLOCKER_STAY_NOT_IN_HOUSE = 'STAY_NOT_IN_HOUSE';
    public const BLOCKER_CHECKOUT_NOT_IMPLEMENTED = 'CHECKOUT_EXECUTION_NOT_YET_IMPLEMENTED';

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

        // Gate 4: Financial settlement evidence (MISSING — Finance-owned, no Front Desk projection)
        $blockerCodes[] = self::BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE;
        $blockerMessages[] = 'Authoritative financial settlement evidence is not available to Front Desk. Folio balance, payment settlement, and deposit application are owned by Finance/PMS. No Front Desk-accessible settlement projection exists.';
        $authoritativeGates['financial_settlement'] = [
            'gate' => 'Folio balance is settled or transferred through an approved authoritative process',
            'owner' => 'Finance / PMS',
            'satisfied' => false,
            'detail' => 'Authoritative financial settlement source is unavailable. Finance/PMS owns folio, payment, deposit, and settlement evidence.',
        ];

        // Gate 5: Cashier obligation evidence (MISSING — General Cashier-owned, no Front Desk projection)
        $blockerCodes[] = self::BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE;
        $blockerMessages[] = 'Authoritative cashier obligation evidence is not available to Front Desk. Cashier session, payment instrument, and cash accountability are owned by General Cashier. No Front Desk-accessible cashier obligation projection exists.';
        $authoritativeGates['cashier_obligation'] = [
            'gate' => 'No unresolved payment/cashier obligation',
            'owner' => 'General Cashier',
            'satisfied' => false,
            'detail' => 'Authoritative cashier obligation source is unavailable. General Cashier owns cashier session and payment instrument evidence.',
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

        // Determine overall status
        $canExecute = false;

        if (empty($blockerCodes)) {
            $overallStatus = FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum::ExecutionBoundaryReady->value;
            $canExecute = true;
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
            'execution_not_performed_marker' => 'Checkout execution is not performed in FD-B8.',
            'financial_settlement_marker' => 'Financial settlement: Not evaluated in Front Desk Package B8. Owned by Finance/PMS.',
            'evaluated_at' => now()->toISOString(),
        ];
    }

    private function authorizeView(User $actor): string
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session('active_company_id');

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (! $property) {
            throw new HttpException(403, 'Active property is required.');
        }

        if (! $actor->can(self::VIEW_PERMISSION)) {
            throw new HttpException(403, 'Front Desk checkout execution boundary view permission is required.');
        }

        return $propertyId;
    }
}

<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutFinalReviewProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    public function finalReview(User $actor, string $frontDeskStayId): array
    {
        $propertyId = $this->authorizeView($actor);
        $stay = FrontDeskStay::withoutGlobalScopes()->whereKey($frontDeskStayId)->where('property_id', $propertyId)->where('status', FrontDeskStayStatusEnum::InHouse->value)->first();
        if (!$stay) throw new HttpException(404, 'Front Desk stay not found.');

        $entries = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()->with(['createdBy'])
            ->where('property_id', $propertyId)->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')->orderBy('created_at', 'desc')->limit(50)->get()
            ->map(fn(FrontDeskDepartureCheckoutFinalReview $e) => [
                'id' => $e->id, 'final_review_status' => $e->final_review_status?->value,
                'final_review_status_label' => $this->statusLabel($e->final_review_status?->value),
                'final_review_note' => $e->final_review_note, 'occurred_at' => $e->occurred_at?->toISOString(),
                'created_by' => $e->created_by, 'created_by_name' => $e->createdBy?->name, 'source_hash' => $e->source_hash,
            ])->values()->all();

        $b6 = FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()
            ->where('property_id', $propertyId)->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')->orderBy('created_at', 'desc')->first();

        $canCreate = $actor->can(FrontDeskDepartureCheckoutFinalReviewService::CREATE_PERMISSION);

        return [
            'stay_id' => $frontDeskStayId, 'property_id' => $propertyId,
            'latest_final_review' => $entries[0] ?? null, 'final_review_history' => $entries, 'final_review_count' => count($entries),
            'b6_checkout_authorization_dependency' => $b6 ? ['id' => $b6->id, 'authorization_status' => $b6->authorization_status?->value, 'authorization_status_label' => $this->b6Label($b6->authorization_status?->value), 'authorization_note' => $b6->authorization_note, 'occurred_at' => $b6->occurred_at?->toISOString()] : null,
            'b6_exists' => $b6 !== null, 'b6_blocked' => $b6 ? ($b6->authorization_status?->value !== 'CHECKOUT_AUTHORIZATION_READY') : false,
            'actions' => ['can_create_checkout_final_review' => $canCreate],
            'allowed_final_review_statuses' => $canCreate ? [['value' => 'CHECKOUT_FINAL_REVIEW_READY', 'label' => 'Mark Final Review Ready'], ['value' => 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'label' => 'Mark Final Review Blocked'], ['value' => 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'label' => 'Mark Final Review Reviewed']] : [],
            'final_review_warning' => !$b6 ? 'No FD-B6 checkout authorization evidence exists. CHECKOUT_FINAL_REVIEW_READY requires latest FD-B6 CHECKOUT_AUTHORIZATION_READY.' : ($b6->authorization_status?->value !== 'CHECKOUT_AUTHORIZATION_READY' ? 'Latest FD-B6 checkout authorization is not CHECKOUT_AUTHORIZATION_READY. CHECKOUT_FINAL_REVIEW_READY requires latest FD-B6 CHECKOUT_AUTHORIZATION_READY.' : null),
            'checkout_execution_marker' => 'Checkout execution: Not performed in Front Desk Package B7.',
            'stay_closure_marker' => 'Stay closure: Not performed in Front Desk Package B7.',
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B7.',
        ];
    }

    private function statusLabel(?string $s): string { return match($s) { 'CHECKOUT_FINAL_REVIEW_READY' => 'Final Review Ready', 'CHECKOUT_FINAL_REVIEW_BLOCKED' => 'Final Review Blocked', 'CHECKOUT_FINAL_REVIEW_REVIEWED' => 'Final Review Reviewed', default => $s ?? 'Unknown' }; }
    private function b6Label(?string $s): string { return match($s) { 'CHECKOUT_AUTHORIZATION_READY' => 'Authorization Ready', 'CHECKOUT_AUTHORIZATION_BLOCKED' => 'Authorization Blocked', 'CHECKOUT_AUTHORIZATION_REVIEWED' => 'Authorization Reviewed', default => $s ?? 'Unknown' }; }
    private function authorizeView(User $actor): string
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session('active_company_id');
        $property = Property::withoutGlobalScopes()->whereKey($propertyId)->where('company_id', $companyId)->where('is_active', true)->first();
        if (!$property) throw new HttpException(403, 'Active property is required.');
        if (!$actor->can(self::VIEW_PERMISSION)) throw new HttpException(403, 'Front Desk departure preparation view permission is required.');
        return $propertyId;
    }
}

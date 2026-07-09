<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutAuthorizationProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    public function authorization(User $actor, string $frontDeskStayId): array
    {
        $propertyId = $this->authorizeView($actor);
        $stay = FrontDeskStay::withoutGlobalScopes()->whereKey($frontDeskStayId)->where('property_id', $propertyId)->where('status', FrontDeskStayStatusEnum::InHouse->value)->first();
        if (!$stay) throw new HttpException(404, 'Front Desk stay not found.');

        $entries = FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()->with(['createdBy'])
            ->where('property_id', $propertyId)->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')->orderBy('created_at', 'desc')->limit(50)->get()
            ->map(fn(FrontDeskDepartureCheckoutAuthorization $e) => [
                'id' => $e->id, 'authorization_status' => $e->authorization_status?->value,
                'authorization_status_label' => $this->statusLabel($e->authorization_status?->value),
                'authorization_note' => $e->authorization_note, 'occurred_at' => $e->occurred_at?->toISOString(),
                'created_by' => $e->created_by, 'created_by_name' => $e->createdBy?->name, 'source_hash' => $e->source_hash,
            ])->values()->all();

        $b5 = FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
            ->where('property_id', $propertyId)->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')->orderBy('created_at', 'desc')->first();

        $canCreate = $actor->can(FrontDeskDepartureCheckoutAuthorizationService::CREATE_PERMISSION);

        return [
            'stay_id' => $frontDeskStayId, 'property_id' => $propertyId,
            'latest_authorization' => $entries[0] ?? null, 'authorization_history' => $entries, 'authorization_count' => count($entries),
            'b5_eligibility_dependency' => $b5 ? ['id' => $b5->id, 'eligibility_status' => $b5->eligibility_status?->value, 'eligibility_status_label' => $this->eligLabel($b5->eligibility_status?->value), 'eligibility_note' => $b5->eligibility_note, 'occurred_at' => $b5->occurred_at?->toISOString()] : null,
            'b5_exists' => $b5 !== null, 'b5_blocked' => $b5 ? ($b5->eligibility_status?->value !== 'CHECKOUT_ELIGIBLE') : false,
            'actions' => ['can_create_checkout_authorization' => $canCreate],
            'allowed_authorization_statuses' => $canCreate ? [['value' => 'CHECKOUT_AUTHORIZATION_READY', 'label' => 'Mark Authorization Ready'], ['value' => 'CHECKOUT_AUTHORIZATION_BLOCKED', 'label' => 'Mark Authorization Blocked'], ['value' => 'CHECKOUT_AUTHORIZATION_REVIEWED', 'label' => 'Mark Authorization Reviewed']] : [],
            'authorization_warning' => !$b5 ? 'No FD-B5 checkout eligibility evidence exists. CHECKOUT_AUTHORIZATION_READY requires latest FD-B5 CHECKOUT_ELIGIBLE.' : ($b5->eligibility_status?->value !== 'CHECKOUT_ELIGIBLE' ? 'Latest FD-B5 checkout eligibility is not CHECKOUT_ELIGIBLE. CHECKOUT_AUTHORIZATION_READY requires latest FD-B5 CHECKOUT_ELIGIBLE.' : null),
            'checkout_execution_marker' => 'Checkout execution: Not performed in Front Desk Package B6.',
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B6.',
        ];
    }

    private function statusLabel(?string $s): string { return match($s) { 'CHECKOUT_AUTHORIZATION_READY' => 'Authorization Ready', 'CHECKOUT_AUTHORIZATION_BLOCKED' => 'Authorization Blocked', 'CHECKOUT_AUTHORIZATION_REVIEWED' => 'Authorization Reviewed', default => $s ?? 'Unknown' }; }
    private function eligLabel(?string $s): string { return match($s) { 'CHECKOUT_ELIGIBLE' => 'Checkout Eligible', 'CHECKOUT_BLOCKED' => 'Checkout Blocked', 'CHECKOUT_REVIEWED' => 'Checkout Reviewed', default => $s ?? 'Unknown' }; }
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

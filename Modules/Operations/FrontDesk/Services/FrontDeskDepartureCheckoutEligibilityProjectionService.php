<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutEligibilityProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    /**
     * @return array<string, mixed>
     */
    public function eligibility(User $actor, string $frontDeskStayId): array
    {
        $propertyId = $this->authorizeView($actor);

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($frontDeskStayId)
            ->where('property_id', $propertyId)
            ->where('status', FrontDeskStayStatusEnum::InHouse->value)
            ->first();

        if (! $stay) {
            throw new HttpException(404, 'Front Desk stay not found.');
        }

        $eligibilityEntries = FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (FrontDeskDepartureCheckoutEligibility $entry) => [
                'id' => $entry->id,
                'eligibility_status' => $entry->eligibility_status?->value,
                'eligibility_status_label' => $this->eligibilityStatusLabel($entry->eligibility_status?->value),
                'eligibility_note' => $entry->eligibility_note,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'created_by' => $entry->created_by,
                'created_by_name' => $entry->createdBy?->name,
                'source_hash' => $entry->source_hash,
            ])
            ->values()
            ->all();

        $latestEligibility = $eligibilityEntries[0] ?? null;

        $b4ClosureReadiness = $this->projectB4ClosureReadinessDependency($propertyId, $frontDeskStayId);

        $canCreate = $actor->can(FrontDeskDepartureCheckoutEligibilityService::CREATE_PERMISSION);

        return [
            'stay_id' => $frontDeskStayId,
            'property_id' => $propertyId,
            'latest_eligibility' => $latestEligibility,
            'eligibility_history' => $eligibilityEntries,
            'eligibility_count' => count($eligibilityEntries),
            'b4_closure_readiness_dependency' => $b4ClosureReadiness,
            'b4_blocked' => $b4ClosureReadiness
                ? ($b4ClosureReadiness['readiness_status'] === 'CLOSURE_BLOCKED')
                : false,
            'b4_exists' => $b4ClosureReadiness !== null,
            'actions' => [
                'can_create_checkout_eligibility' => $canCreate,
            ],
            'allowed_eligibility_statuses' => $canCreate ? [
                ['value' => 'CHECKOUT_ELIGIBLE', 'label' => 'Mark Checkout Eligible'],
                ['value' => 'CHECKOUT_BLOCKED', 'label' => 'Mark Checkout Blocked'],
                ['value' => 'CHECKOUT_REVIEWED', 'label' => 'Mark Checkout Reviewed'],
            ] : [],
            'checkout_eligibility_warning' => !$b4ClosureReadiness
                ? 'No FD-B4 closure readiness evidence exists. CHECKOUT_ELIGIBLE status requires at least one closure readiness.'
                : ($b4ClosureReadiness['readiness_status'] === 'CLOSURE_BLOCKED'
                    ? 'Latest FD-B4 closure readiness is blocked. CHECKOUT_ELIGIBLE requires the latest closure readiness to not be blocked.'
                    : null),
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B5.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function projectB4ClosureReadinessDependency(string $propertyId, string $stayId): ?array
    {
        $readiness = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $readiness) {
            return null;
        }

        return [
            'id' => $readiness->id,
            'readiness_status' => $readiness->readiness_status?->value,
            'readiness_status_label' => $this->closureReadinessStatusLabel($readiness->readiness_status?->value),
            'readiness_note' => $readiness->readiness_note,
            'occurred_at' => $readiness->occurred_at?->toISOString(),
        ];
    }

    private function eligibilityStatusLabel(?string $status): string
    {
        return match ($status) {
            'CHECKOUT_ELIGIBLE' => 'Checkout Eligible',
            'CHECKOUT_BLOCKED' => 'Checkout Blocked',
            'CHECKOUT_REVIEWED' => 'Checkout Reviewed',
            default => $status ?? 'Unknown',
        };
    }

    private function closureReadinessStatusLabel(?string $status): string
    {
        return match ($status) {
            'CLOSURE_READY' => 'Closure Ready',
            'CLOSURE_BLOCKED' => 'Closure Blocked',
            'CLOSURE_REVIEWED' => 'Closure Reviewed',
            default => $status ?? 'Unknown',
        };
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
            throw new HttpException(403, 'Front Desk departure preparation view permission is required.');
        }

        return $propertyId;
    }
}

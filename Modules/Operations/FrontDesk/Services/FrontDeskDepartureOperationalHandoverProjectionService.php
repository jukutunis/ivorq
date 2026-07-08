<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureOperationalHandoverProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    /**
     * @return array<string, mixed>
     */
    public function handover(User $actor, string $frontDeskStayId): array
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

        $handovers = FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (FrontDeskDepartureOperationalHandover $handover) => [
                'id' => $handover->id,
                'handover_status' => $handover->handover_status?->value,
                'handover_status_label' => $this->handoverStatusLabel($handover->handover_status?->value),
                'handover_note' => $handover->handover_note,
                'occurred_at' => $handover->occurred_at?->toISOString(),
                'created_by' => $handover->created_by,
                'created_by_name' => $handover->createdBy?->name,
                'source_hash' => $handover->source_hash,
            ])
            ->values()
            ->all();

        $latestHandover = $handovers[0] ?? null;

        $canCreate = $actor->can(FrontDeskDepartureOperationalHandoverService::CREATE_PERMISSION);

        return [
            'stay_id' => $frontDeskStayId,
            'property_id' => $propertyId,
            'latest_handover' => $latestHandover,
            'handover_history' => $handovers,
            'handover_count' => count($handovers),
            'actions' => [
                'can_create_handover' => $canCreate,
            ],
            'allowed_handover_statuses' => $canCreate ? [
                ['value' => 'OPERATIONAL_HANDOVER_READY', 'label' => 'Mark Operationally Ready'],
                ['value' => 'OPERATIONAL_HANDOVER_BLOCKED', 'label' => 'Mark Operationally Blocked'],
                ['value' => 'OPERATIONAL_HANDOVER_REVIEWED', 'label' => 'Mark Reviewed'],
            ] : [],
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B3.',
        ];
    }

    private function handoverStatusLabel(?string $status): string
    {
        return match ($status) {
            'OPERATIONAL_HANDOVER_READY' => 'Operationally Ready',
            'OPERATIONAL_HANDOVER_BLOCKED' => 'Operationally Blocked',
            'OPERATIONAL_HANDOVER_REVIEWED' => 'Reviewed',
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

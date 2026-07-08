<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDeparturePreparationEventProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    /**
     * @return array<string, mixed>
     */
    public function actionLog(User $actor, string $frontDeskStayId): array
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

        $events = FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (FrontDeskDeparturePreparationEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type?->value,
                'event_type_label' => $this->eventTypeLabel($event->event_type?->value),
                'note' => $event->note,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'created_by' => $event->created_by,
                'created_by_name' => $event->createdBy?->name,
                'source_hash' => $event->source_hash,
            ])
            ->values()
            ->all();

        $canCreate = $actor->can(FrontDeskDeparturePreparationEventService::CREATE_PERMISSION);

        return [
            'stay_id' => $frontDeskStayId,
            'property_id' => $propertyId,
            'events' => $events,
            'event_count' => count($events),
            'actions' => [
                'can_create_event' => $canCreate,
            ],
            'allowed_event_types' => $canCreate ? [
                ['value' => 'DEPARTURE_NOTE_RECORDED', 'label' => 'Record Note'],
                ['value' => 'DEPARTURE_TIME_CONFIRMED', 'label' => 'Confirm Departure Time'],
                ['value' => 'LUGGAGE_ASSISTANCE_NOTED', 'label' => 'Luggage Assistance'],
                ['value' => 'TRANSPORTATION_NOTED', 'label' => 'Transportation'],
                ['value' => 'OPERATIONAL_BLOCKER_ACKNOWLEDGED', 'label' => 'Acknowledge Blocker'],
                ['value' => 'GUEST_MESSAGE_NOTED', 'label' => 'Guest Message'],
            ] : [],
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B2.',
        ];
    }

    private function eventTypeLabel(?string $type): string
    {
        return match ($type) {
            'DEPARTURE_NOTE_RECORDED' => 'Departure Note',
            'DEPARTURE_TIME_CONFIRMED' => 'Departure Time Confirmed',
            'LUGGAGE_ASSISTANCE_NOTED' => 'Luggage Assistance',
            'TRANSPORTATION_NOTED' => 'Transportation',
            'OPERATIONAL_BLOCKER_ACKNOWLEDGED' => 'Operational Blocker Acknowledged',
            'GUEST_MESSAGE_NOTED' => 'Guest Message',
            default => $type ?? 'Unknown',
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

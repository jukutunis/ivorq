<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDeparturePreparationEventTypeEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDeparturePreparationEventService
{
    public const CREATE_PERMISSION = 'frontdesk.departure-preparation.event.create';

    private const ALLOWED_EVENT_TYPES = [
        'DEPARTURE_NOTE_RECORDED',
        'DEPARTURE_TIME_CONFIRMED',
        'LUGGAGE_ASSISTANCE_NOTED',
        'TRANSPORTATION_NOTED',
        'OPERATIONAL_BLOCKER_ACKNOWLEDGED',
        'GUEST_MESSAGE_NOTED',
    ];

    private const MAX_NOTE_LENGTH = 2000;

    /**
     * @return array{event: FrontDeskDeparturePreparationEvent, replayed: bool}
     */
    public function create(
        User $actor,
        string $frontDeskStayId,
        string $eventType,
        ?string $note,
        string $idempotencyKey
    ): array {
        $propertyId = $this->authorizeCreate($actor);

        $eventTypeEnum = $this->validateEventType($eventType);
        $this->validateNoteLength($note);

        return DB::transaction(function () use (
            $actor, $propertyId, $frontDeskStayId, $eventTypeEnum, $note, $idempotencyKey
        ) {
            $stay = $this->lockStay($frontDeskStayId, $propertyId);

            $existing = $this->findIdempotentDuplicate($propertyId, $idempotencyKey);
            if ($existing) {
                return ['event' => $existing, 'replayed' => true];
            }

            $occurredAt = Carbon::now();
            $sourceHash = $this->computeSourceHash($frontDeskStayId, $eventTypeEnum->value, $note ?? '', $occurredAt);

            $sourceDuplicate = FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('front_desk_stay_id', $frontDeskStayId)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($sourceDuplicate) {
                return ['event' => $sourceDuplicate, 'replayed' => true];
            }

            $event = FrontDeskDeparturePreparationEvent::create([
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'room_id' => $stay->current_room_id,
                'event_type' => $eventTypeEnum->value,
                'note' => $note,
                'occurred_at' => $occurredAt,
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return ['event' => $event, 'replayed' => false];
        });
    }

    private function lockStay(string $stayId, string $propertyId): FrontDeskStay
    {
        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($stayId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        if (! $stay) {
            throw new DomainException('Front Desk stay not found for active property.');
        }

        if ($stay->status !== FrontDeskStayStatusEnum::InHouse) {
            throw new DomainException('Departure preparation events require an IN_HOUSE stay.');
        }

        return $stay;
    }

    private function findIdempotentDuplicate(string $propertyId, string $idempotencyKey): ?FrontDeskDeparturePreparationEvent
    {
        return FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function computeSourceHash(string $stayId, string $eventType, string $note, Carbon $occurredAt): string
    {
        return hash('sha256', implode('|', [
            $stayId,
            $eventType,
            $note,
            $occurredAt->toISOString(),
        ]));
    }

    private function authorizeCreate(User $actor): string
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

        if (! $actor->can(self::CREATE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk departure preparation event create permission is required.');
        }

        return $propertyId;
    }

    private function validateEventType(string $eventType): FrontDeskDeparturePreparationEventTypeEnum
    {
        $enum = FrontDeskDeparturePreparationEventTypeEnum::tryFrom($eventType);

        if (! $enum) {
            throw new DomainException(
                'Invalid event type. Allowed: ' . implode(', ', self::ALLOWED_EVENT_TYPES) . '.'
            );
        }

        if (! in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            throw new DomainException(
                'Event type not allowed for departure preparation: ' . $eventType
            );
        }

        return $enum;
    }

    private function validateNoteLength(?string $note): void
    {
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new DomainException(
                'Note must not exceed ' . self::MAX_NOTE_LENGTH . ' characters.'
            );
        }
    }
}

<?php

namespace Modules\Operations\FrontDesk\Services;

use Illuminate\Support\Carbon;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Enums\FrontDeskDeparturePreparationEventTypeEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureQueueProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    public const DUE_OUT_TODAY = 'DUE_OUT_TODAY';
    public const DUE_OUT_TOMORROW = 'DUE_OUT_TOMORROW';
    public const DUE_OUT_FUTURE = 'DUE_OUT_FUTURE';
    public const OVERDUE_DEPARTURE = 'OVERDUE_DEPARTURE';
    public const DEPARTURE_DATE_UNKNOWN = 'DEPARTURE_DATE_UNKNOWN';

    public const DEPARTURE_OPERATIONALLY_READY = 'DEPARTURE_OPERATIONALLY_READY';
    public const DEPARTURE_OPERATIONALLY_BLOCKED = 'DEPARTURE_OPERATIONALLY_BLOCKED';
    public const DEPARTURE_READINESS_UNKNOWN = 'DEPARTURE_READINESS_UNKNOWN';

    public function __construct(
        private readonly EngineeringAvailabilityDependencyService $engineeringAvailability,
        private readonly HousekeepingReadinessDependencyService $housekeepingReadiness,
        private readonly FrontDeskCheckoutReadinessProjectionService $checkoutReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function queue(User $actor): array
    {
        [$propertyId, $property] = $this->authorizeView($actor);

        $today = Carbon::now($property->timezone ?: config('app.timezone'))->toDateString();
        $tomorrow = Carbon::now($property->timezone ?: config('app.timezone'))->addDay()->toDateString();

        $stays = FrontDeskStay::withoutGlobalScopes()
            ->with([
                'reservation' => fn ($query) => $query->withoutGlobalScopes(),
                'guest' => fn ($query) => $query->withoutGlobalScopes(),
                'currentRoom' => fn ($query) => $query->withoutGlobalScopes(),
                'currentAssignment' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('property_id', $propertyId)
            ->where('status', FrontDeskStayStatusEnum::InHouse->value)
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn (FrontDeskStay $stay) => $this->projectStay($actor, $stay, $propertyId, $today, $tomorrow))
            ->values();

        $dueOutToday = $stays->where('due_out_classification', self::DUE_OUT_TODAY);
        $overdue = $stays->where('due_out_classification', self::OVERDUE_DEPARTURE);
        $operationallyReady = $stays->where('departure_readiness', self::DEPARTURE_OPERATIONALLY_READY);
        $operationallyBlocked = $stays->where('departure_readiness', self::DEPARTURE_OPERATIONALLY_BLOCKED);

        return [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'company_id' => $property->company_id,
            ],
            'evaluated_at' => now()->toISOString(),
            'snapshots' => [
                'dueOutToday' => $dueOutToday->count(),
                'dueOutTomorrow' => $stays->where('due_out_classification', self::DUE_OUT_TOMORROW)->count(),
                'dueOutFuture' => $stays->where('due_out_classification', self::DUE_OUT_FUTURE)->count(),
                'overdueDeparture' => $overdue->count(),
                'departureDateUnknown' => $stays->where('due_out_classification', self::DEPARTURE_DATE_UNKNOWN)->count(),
                'departureOperationallyReady' => $operationallyReady->count(),
                'departureOperationallyBlocked' => $operationallyBlocked->count(),
            ],
            'views' => [
                'dueOutToday' => $dueOutToday->values()->all(),
                'dueOutTomorrow' => $stays->where('due_out_classification', self::DUE_OUT_TOMORROW)->values()->all(),
                'dueOutFuture' => $stays->where('due_out_classification', self::DUE_OUT_FUTURE)->values()->all(),
                'overdueDepartures' => $overdue->values()->all(),
            ],
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B3.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectStay(User $actor, FrontDeskStay $stay, string $propertyId, string $today, string $tomorrow): array
    {
        $departureDate = $stay->reservation?->departure_date?->toDateString();
        $dueOutClassification = $this->classifyDueOut($departureDate, $today, $tomorrow);

        $currentRoom = $stay->currentRoom;
        $roomId = $currentRoom?->id;

        $housekeeping = $this->projectHousekeeping($actor, $roomId);
        $engineering = $this->projectEngineering($actor, $roomId);
        $checkoutReadiness = $this->projectCheckoutReadinessOrNull($actor, $stay);

        $blockingReasons = $this->collectBlockingReasons($housekeeping, $engineering, $checkoutReadiness);

        $departureReadiness = match (true) {
            $roomId === null => self::DEPARTURE_READINESS_UNKNOWN,
            $dueOutClassification === self::DEPARTURE_DATE_UNKNOWN => self::DEPARTURE_READINESS_UNKNOWN,
            $blockingReasons === [] => self::DEPARTURE_OPERATIONALLY_READY,
            default => self::DEPARTURE_OPERATIONALLY_BLOCKED,
        };

        return [
            'stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'reservation_number' => $stay->reservation?->reservation_number,
            'guest' => [
                'id' => $stay->guest_id,
                'name' => $stay->guest?->full_name,
                'vip_level' => $stay->guest?->vip_level,
            ],
            'room' => [
                'id' => $stay->current_room_id,
                'number' => $currentRoom?->room_number,
                'room_type' => $this->enumValue($currentRoom?->room_type),
            ],
            'expected_departure_date' => $departureDate,
            'due_out_classification' => $dueOutClassification,
            'front_desk_stay_status' => $this->enumValue($stay->status),
            'current_room_assignment_id' => $stay->current_room_assignment_id,
            'checked_in_at' => $stay->checked_in_at?->toISOString(),
            'housekeeping_readiness_status' => $housekeeping['readiness_status'],
            'engineering_availability_status' => $engineering['availability_status'],
            'operational_checkout_readiness' => $checkoutReadiness
                ? $checkoutReadiness['readiness_status']
                : 'NOT_EVALUATED',
            'departure_readiness' => $departureReadiness,
            'blocking_reasons' => $blockingReasons,
            'departure_preparation_events' => $this->projectEvents($propertyId, $stay->id),
            'can_create_departure_preparation_event' => $actor->can(FrontDeskDeparturePreparationEventService::CREATE_PERMISSION),
            'allowed_event_types' => $actor->can(FrontDeskDeparturePreparationEventService::CREATE_PERMISSION)
                ? $this->allowedEventTypesForWorkspace()
                : [],
            'departure_operational_handover' => $this->projectHandover($propertyId, $stay->id),
            'can_create_operational_handover' => $actor->can(FrontDeskDepartureOperationalHandoverService::CREATE_PERMISSION),
            'allowed_handover_statuses' => $actor->can(FrontDeskDepartureOperationalHandoverService::CREATE_PERMISSION)
                ? $this->allowedHandoverStatusesForWorkspace()
                : [],
            'departure_closure_readiness' => $this->projectClosureReadiness($propertyId, $stay->id),
            'can_create_closure_readiness' => $actor->can(FrontDeskDepartureClosureReadinessService::CREATE_PERMISSION),
            'allowed_closure_readiness_statuses' => $actor->can(FrontDeskDepartureClosureReadinessService::CREATE_PERMISSION)
                ? $this->allowedClosureReadinessStatusesForWorkspace()
                : [],
            'departure_checkout_eligibility' => $this->projectCheckoutEligibility($propertyId, $stay->id),
            'can_create_checkout_eligibility' => $actor->can(FrontDeskDepartureCheckoutEligibilityService::CREATE_PERMISSION),
            'allowed_checkout_eligibility_statuses' => $actor->can(FrontDeskDepartureCheckoutEligibilityService::CREATE_PERMISSION)
                ? $this->allowedCheckoutEligibilityStatusesForWorkspace()
                : [],
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B3.',
            'evaluated_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectEvents(string $propertyId, string $stayId): array
    {
        return FrontDeskDeparturePreparationEvent::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (FrontDeskDeparturePreparationEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type?->value,
                'event_type_label' => $this->eventTypeLabel($event->event_type?->value),
                'note' => $event->note,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'created_by_name' => $event->createdBy?->name,
                'source_hash' => $event->source_hash,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function allowedEventTypesForWorkspace(): array
    {
        return [
            ['value' => 'DEPARTURE_NOTE_RECORDED', 'label' => 'Record Note'],
            ['value' => 'DEPARTURE_TIME_CONFIRMED', 'label' => 'Confirm Departure Time'],
            ['value' => 'LUGGAGE_ASSISTANCE_NOTED', 'label' => 'Luggage Assistance'],
            ['value' => 'TRANSPORTATION_NOTED', 'label' => 'Transportation'],
            ['value' => 'OPERATIONAL_BLOCKER_ACKNOWLEDGED', 'label' => 'Acknowledge Blocker'],
            ['value' => 'GUEST_MESSAGE_NOTED', 'label' => 'Guest Message'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function projectHandover(string $propertyId, string $stayId): ?array
    {
        $handovers = FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (FrontDeskDepartureOperationalHandover $handover) => [
                'id' => $handover->id,
                'handover_status' => $handover->handover_status?->value,
                'handover_status_label' => $this->handoverStatusLabel($handover->handover_status?->value),
                'handover_note' => $handover->handover_note,
                'occurred_at' => $handover->occurred_at?->toISOString(),
                'created_by_name' => $handover->createdBy?->name,
                'source_hash' => $handover->source_hash,
            ])
            ->values()
            ->all();

        if (empty($handovers)) {
            return null;
        }

        return [
            'latest' => $handovers[0],
            'history' => $handovers,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function allowedHandoverStatusesForWorkspace(): array
    {
        return [
            ['value' => 'OPERATIONAL_HANDOVER_READY', 'label' => 'Mark Operationally Ready'],
            ['value' => 'OPERATIONAL_HANDOVER_BLOCKED', 'label' => 'Mark Operationally Blocked'],
            ['value' => 'OPERATIONAL_HANDOVER_REVIEWED', 'label' => 'Mark Reviewed'],
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

    /**
     * @return array<string, mixed>|null
     */
    private function projectClosureReadiness(string $propertyId, string $stayId): ?array
    {
        $entries = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (FrontDeskDepartureClosureReadiness $entry) => [
                'id' => $entry->id,
                'readiness_status' => $entry->readiness_status?->value,
                'readiness_status_label' => $this->closureReadinessStatusLabel($entry->readiness_status?->value),
                'readiness_note' => $entry->readiness_note,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'created_by_name' => $entry->createdBy?->name,
                'source_hash' => $entry->source_hash,
            ])
            ->values()
            ->all();

        if (empty($entries)) {
            return null;
        }

        // Check B3 handover dependency
        $b3Handover = FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        $closureReadinessWarning = null;
        if (! $b3Handover) {
            $closureReadinessWarning = 'No FD-B3 operational handover evidence exists. CLOSURE_READY status requires at least one handover.';
        } elseif ($b3Handover->handover_status?->value === 'OPERATIONAL_HANDOVER_BLOCKED') {
            $closureReadinessWarning = 'Latest FD-B3 operational handover is blocked. CLOSURE_READY requires the latest handover to not be blocked.';
        }

        return [
            'latest' => $entries[0],
            'history' => $entries,
            'b3_handover_dependency' => $b3Handover ? [
                'id' => $b3Handover->id,
                'handover_status' => $b3Handover->handover_status?->value,
                'handover_status_label' => $this->handoverStatusLabel($b3Handover->handover_status?->value),
                'handover_note' => $b3Handover->handover_note,
                'occurred_at' => $b3Handover->occurred_at?->toISOString(),
            ] : null,
            'b3_exists' => $b3Handover !== null,
            'b3_blocked' => $b3Handover ? ($b3Handover->handover_status?->value === 'OPERATIONAL_HANDOVER_BLOCKED') : false,
            'closure_readiness_warning' => $closureReadinessWarning,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function allowedClosureReadinessStatusesForWorkspace(): array
    {
        return [
            ['value' => 'CLOSURE_READY', 'label' => 'Mark Closure Ready'],
            ['value' => 'CLOSURE_BLOCKED', 'label' => 'Mark Closure Blocked'],
            ['value' => 'CLOSURE_REVIEWED', 'label' => 'Mark Closure Reviewed'],
        ];
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

    /**
     * @return array<string, mixed>|null
     */
    private function projectCheckoutEligibility(string $propertyId, string $stayId): ?array
    {
        $entries = FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (FrontDeskDepartureCheckoutEligibility $entry) => [
                'id' => $entry->id,
                'eligibility_status' => $entry->eligibility_status?->value,
                'eligibility_status_label' => $this->checkoutEligibilityStatusLabel($entry->eligibility_status?->value),
                'eligibility_note' => $entry->eligibility_note,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'created_by_name' => $entry->createdBy?->name,
                'source_hash' => $entry->source_hash,
            ])
            ->values()
            ->all();

        if (empty($entries)) {
            return null;
        }

        $b4Readiness = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        $checkoutEligibilityWarning = null;
        if (! $b4Readiness) {
            $checkoutEligibilityWarning = 'No FD-B4 closure readiness evidence exists. CHECKOUT_ELIGIBLE requires at least one closure readiness.';
        } elseif ($b4Readiness->readiness_status?->value === 'CLOSURE_BLOCKED') {
            $checkoutEligibilityWarning = 'Latest FD-B4 closure readiness is blocked. CHECKOUT_ELIGIBLE requires the latest closure readiness to not be blocked.';
        }

        return [
            'latest' => $entries[0],
            'history' => $entries,
            'b4_closure_readiness_dependency' => $b4Readiness ? [
                'id' => $b4Readiness->id,
                'readiness_status' => $b4Readiness->readiness_status?->value,
                'readiness_status_label' => $this->closureReadinessStatusLabel($b4Readiness->readiness_status?->value),
                'readiness_note' => $b4Readiness->readiness_note,
                'occurred_at' => $b4Readiness->occurred_at?->toISOString(),
            ] : null,
            'b4_exists' => $b4Readiness !== null,
            'b4_blocked' => $b4Readiness ? ($b4Readiness->readiness_status?->value === 'CLOSURE_BLOCKED') : false,
            'checkout_eligibility_warning' => $checkoutEligibilityWarning,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function allowedCheckoutEligibilityStatusesForWorkspace(): array
    {
        return [
            ['value' => 'CHECKOUT_ELIGIBLE', 'label' => 'Mark Checkout Eligible'],
            ['value' => 'CHECKOUT_BLOCKED', 'label' => 'Mark Checkout Blocked'],
            ['value' => 'CHECKOUT_REVIEWED', 'label' => 'Mark Checkout Reviewed'],
        ];
    }

    private function checkoutEligibilityStatusLabel(?string $status): string
    {
        return match ($status) {
            'CHECKOUT_ELIGIBLE' => 'Checkout Eligible',
            'CHECKOUT_BLOCKED' => 'Checkout Blocked',
            'CHECKOUT_REVIEWED' => 'Checkout Reviewed',
            default => $status ?? 'Unknown',
        };
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

    private function classifyDueOut(?string $departureDate, string $today, string $tomorrow): string
    {
        if ($departureDate === null) {
            return self::DEPARTURE_DATE_UNKNOWN;
        }

        return match (true) {
            $departureDate < $today => self::OVERDUE_DEPARTURE,
            $departureDate === $today => self::DUE_OUT_TODAY,
            $departureDate === $tomorrow => self::DUE_OUT_TOMORROW,
            default => self::DUE_OUT_FUTURE,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function projectHousekeeping(User $actor, ?string $roomId): array
    {
        if ($roomId === null) {
            return [
                'readiness_status' => HousekeepingRoomReadinessProjectionService::UNKNOWN,
                'source_status' => 'unknown',
                'blocking_reason' => 'Current room is not resolvable.',
            ];
        }

        try {
            $readiness = $this->housekeepingReadiness->roomReadiness($actor, $roomId);

            return [
                'readiness_status' => $readiness['readiness_status'],
                'source_status' => $readiness['source_status'] ?? 'unknown',
                'blocking_reason' => $readiness['blocking_reason'] ?? null,
            ];
        } catch (\Throwable) {
            return [
                'readiness_status' => HousekeepingRoomReadinessProjectionService::UNKNOWN,
                'source_status' => 'error',
                'blocking_reason' => 'Housekeeping readiness evaluation failed.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function projectEngineering(User $actor, ?string $roomId): array
    {
        if ($roomId === null) {
            return [
                'availability_status' => EngineeringRoomAvailabilityProjectionService::UNKNOWN,
                'blocking_reason' => 'Current room is not resolvable.',
            ];
        }

        try {
            $availability = $this->engineeringAvailability->roomAvailability($actor, $roomId);

            return [
                'availability_status' => $availability['availability_status'],
                'blocking_reason' => $availability['blocking_reason'] ?? null,
            ];
        } catch (\Throwable) {
            return [
                'availability_status' => EngineeringRoomAvailabilityProjectionService::UNKNOWN,
                'blocking_reason' => 'Engineering availability evaluation failed.',
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function projectCheckoutReadinessOrNull(User $actor, FrontDeskStay $stay): ?array
    {
        if (! $actor->can(FrontDeskCheckoutReadinessProjectionService::VIEW_PERMISSION)) {
            return null;
        }

        try {
            return $this->checkoutReadiness->ready($actor, $stay->id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return string[]
     */
    private function collectBlockingReasons(array $housekeeping, array $engineering, ?array $checkoutReadiness): array
    {
        $reasons = [];

        if (($housekeeping['readiness_status'] ?? null) === HousekeepingRoomReadinessProjectionService::BLOCKED
            || ($housekeeping['readiness_status'] ?? null) === HousekeepingRoomReadinessProjectionService::UNKNOWN) {
            $reasons[] = $housekeeping['blocking_reason'] ?? 'Housekeeping readiness blocks departure.';
        }

        if (($engineering['availability_status'] ?? null) === EngineeringRoomAvailabilityProjectionService::BLOCKED
            || ($engineering['availability_status'] ?? null) === EngineeringRoomAvailabilityProjectionService::UNKNOWN) {
            $reasons[] = $engineering['blocking_reason'] ?? 'Engineering availability blocks departure.';
        }

        if ($checkoutReadiness !== null
            && ($checkoutReadiness['readiness_status'] ?? null) !== FrontDeskCheckoutReadinessProjectionService::READY) {
            $reasons[] = 'Checkout readiness blocks departure: '
                . implode(' ', $checkoutReadiness['operational_blockers'] ?? []);
        }

        return $reasons;
    }

    /**
     * @return array{0: string, 1: Property}
     */
    private function authorizeView(User $actor): array
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

        return [$propertyId, $property];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}

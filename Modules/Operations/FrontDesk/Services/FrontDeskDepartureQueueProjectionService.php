<?php

namespace Modules\Operations\FrontDesk\Services;

use Illuminate\Support\Carbon;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
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
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B1.',
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
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B1.',
            'evaluated_at' => now()->toISOString(),
        ];
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

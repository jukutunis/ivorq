<?php

namespace Modules\Operations\FrontDesk\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArrivalEligibilityProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.arrival.view';

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function workspace(User $actor, array $filters = []): array
    {
        [$propertyId, $property] = $this->authorizeView($actor);

        $today = Carbon::now($property->timezone ?: config('app.timezone'))->toDateString();
        $search = trim((string) ($filters['search'] ?? ''));
        $date = trim((string) ($filters['arrival_date'] ?? ''));

        $reservations = $this->reservations($propertyId, $search, $date)->map(function (Reservation $reservation) use ($actor, $propertyId, $today) {
            return $this->projectReservation($actor, $reservation, $propertyId, $today);
        })->values();

        $arrivingToday = $reservations
            ->filter(fn (array $row) => $row['arrival_date'] === $today)
            ->values();

        $eligible = $reservations
            ->filter(fn (array $row) => $row['eligibility']['eligible'] === true)
            ->values();

        return [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'company_id' => $property->company_id,
            ],
            'businessRuleDate' => $today,
            'filters' => [
                'search' => $search,
                'arrival_date' => $date,
            ],
            'policy' => [
                'guestRegistrationRequirement' => 'Not configured by canonical source.',
                'identityDocumentRequirement' => 'Not configured by canonical source.',
            ],
            'snapshots' => [
                'totalArrivals' => $arrivingToday->count(),
                'arrivalReady' => $eligible->count(),
                'blockedArrivals' => $reservations->where('eligibility.eligible', false)->count(),
                'unassignedEligible' => $eligible->whereNull('assigned_room.id')->count(),
                'assignedReadyToCheckIn' => $eligible->whereNotNull('assigned_room.id')->count(),
            ],
            'views' => [
                'arrivingToday' => $arrivingToday->values()->all(),
                'expectedArrivals' => $reservations
                    ->filter(fn (array $row) => $row['arrival_date'] > $today)
                    ->values()
                    ->all(),
                'blockedArrivals' => $reservations
                    ->filter(fn (array $row) => $row['eligibility']['eligible'] === false)
                    ->values()
                    ->all(),
                'unassignedEligibleArrivals' => $eligible
                    ->filter(fn (array $row) => $row['assigned_room'] === null)
                    ->values()
                    ->all(),
                'assignedReadyToCheckIn' => $eligible
                    ->filter(fn (array $row) => $row['assigned_room'] !== null)
                    ->values()
                    ->all(),
            ],
            'financeMarker' => 'Financial settlement: Not evaluated in Front Desk Package A.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reservation(User $actor, string $reservationId): array
    {
        [$propertyId, $property] = $this->authorizeView($actor);
        $today = Carbon::now($property->timezone ?: config('app.timezone'))->toDateString();

        $reservation = Reservation::withoutGlobalScopes()
            ->with([
                'primaryGuest' => fn ($query) => $query->withoutGlobalScopes(),
                'assignedRoom' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->whereKey($reservationId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation) {
            throw new HttpException(404, 'Reservation was not found for the active property.');
        }

        return $this->projectReservation($actor, $reservation, $propertyId, $today);
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function reservations(string $propertyId, string $search, string $date): Collection
    {
        return Reservation::withoutGlobalScopes()
            ->with([
                'primaryGuest' => fn ($query) => $query->withoutGlobalScopes(),
                'assignedRoom' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('property_id', $propertyId)
            ->when($date !== '', fn ($query) => $query->whereDate('arrival_date', $date))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('reservation_number', 'ilike', "%{$search}%")
                        ->orWhereHas('primaryGuest', fn ($guest) => $guest->withoutGlobalScopes()->where('full_name', 'ilike', "%{$search}%"));
                });
            })
            ->orderBy('arrival_date')
            ->orderBy('reservation_number')
            ->limit(250)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function projectReservation(User $actor, Reservation $reservation, string $propertyId, string $today): array
    {
        $guest = $reservation->primaryGuest;
        $room = $reservation->assignedRoom;
        $status = $reservation->status instanceof ReservationStatusEnum
            ? $reservation->status->value
            : (string) $reservation->status;

        $blockers = [];

        if ($reservation->property_id !== $propertyId) {
            $blockers[] = 'Reservation does not belong to the active property.';
        }

        if ($status !== ReservationStatusEnum::Confirmed->value) {
            $blockers[] = match ($status) {
                ReservationStatusEnum::Cancelled->value => 'Reservation is cancelled by canonical source.',
                ReservationStatusEnum::NoShow->value => 'Reservation is no-show by canonical source.',
                default => 'Reservation status is not arrival-eligible by canonical source.',
            };
        }

        if ($reservation->arrival_date?->toDateString() > $today) {
            $blockers[] = 'Arrival date is not due under the current business rule.';
        }

        if (! $guest || $guest->property_id !== $propertyId || $guest->id !== $reservation->primary_guest_id) {
            $blockers[] = 'Canonical primary guest linkage is missing or outside the active property.';
        }

        if ($this->hasActiveLegacyStay($reservation->id, $propertyId)) {
            $blockers[] = 'Existing active stay evidence prevents duplicate arrival eligibility.';
        }

        $housekeeping = $this->housekeepingEvidence($room);
        $engineering = $this->engineeringAvailabilityEvidence($actor, $room?->id);

        return [
            'reservation_id' => $reservation->id,
            'reservation_number' => $reservation->reservation_number,
            'reservation_status' => $status,
            'arrival_date' => $reservation->arrival_date?->toDateString(),
            'departure_date' => $reservation->departure_date?->toDateString(),
            'guest' => $guest ? [
                'id' => $guest->id,
                'name' => $guest->full_name,
                'vip_level' => $guest->vip_level,
            ] : null,
            'room_type' => $this->enumValue($reservation->reserved_room_type),
            'assigned_room' => $room ? [
                'id' => $room->id,
                'number' => $room->room_number,
                'room_type' => $this->enumValue($room->room_type),
            ] : null,
            'housekeeping' => $housekeeping,
            'engineering' => $engineering,
            'front_desk' => $this->frontDeskEvidence($reservation->id, $propertyId),
            'eligibility' => [
                'eligible' => $blockers === [],
                'state' => $blockers === [] ? 'ARRIVAL_READY' : 'BLOCKED',
                'blockers' => $blockers,
            ],
            'actions' => $this->frontDeskActions($actor, $reservation->id, $propertyId, $blockers, $housekeeping, $engineering),
            'source_requirements' => [
                'guest_registration' => 'Not configured by canonical source.',
                'identity_document' => 'Not configured by canonical source.',
            ],
        ];
    }

    private function hasActiveLegacyStay(string $reservationId, string $propertyId): bool
    {
        return DB::table('stays')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->whereIn('status', ['reserved', 'checked_in'])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function housekeepingEvidence(mixed $room): array
    {
        if (! $room) {
            return [
                'state' => 'NO_ROOM_ASSIGNED',
                'cleanliness_status' => null,
                'readiness_state' => null,
                'source' => 'Housekeeping Room',
            ];
        }

        return [
            'state' => (string) ($room->readiness_state ?? 'unknown'),
            'cleanliness_status' => $this->enumValue($room->cleanliness_status),
            'readiness_state' => (string) ($room->readiness_state ?? 'unknown'),
            'source' => 'Housekeeping Room',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function engineeringAvailabilityEvidence(User $actor, ?string $roomId): array
    {
        if ($roomId === null) {
            return [
                'state' => 'NOT_EVALUATED_NO_ROOM_ASSIGNED',
                'source' => 'Engineering Availability Projection',
                'active_block_count' => 0,
            ];
        }

        $projection = app(EngineeringRoomAvailabilityProjectionService::class)
            ->forFrontDesk($actor, $roomId);

        return [
            'state' => $projection['availability_status'],
            'source' => 'Engineering Availability Projection',
            'active_block_count' => $projection['availability_status'] === EngineeringRoomAvailabilityProjectionService::BLOCKED ? 1 : 0,
            'blocking_source_type' => $projection['blocking_source_type'],
            'blocking_source_id' => $projection['blocking_source_id'],
            'blocking_reason' => $projection['blocking_reason'],
            'evaluated_at' => $projection['evaluated_at'],
        ];
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
            throw new HttpException(403, 'Front Desk arrival view permission is required.');
        }

        return [$propertyId, $property];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function frontDeskEvidence(string $reservationId, string $propertyId): ?array
    {
        if (! Schema::hasTable('front_desk_stays')) {
            return null;
        }

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->with(['currentRoom' => fn ($query) => $query->withoutGlobalScopes()])
            ->first();

        if (! $stay) {
            return null;
        }

        return [
            'stay_id' => $stay->id,
            'status' => $this->enumValue($stay->status),
            'current_room_id' => $stay->current_room_id,
            'current_room_assignment_id' => $stay->current_room_assignment_id,
            'current_room_number' => $stay->currentRoom?->room_number,
            'checked_in_at' => $stay->checked_in_at?->toISOString(),
        ];
    }

    /**
     * @param string[] $blockers
     * @return array<string, mixed>
     */
    private function frontDeskActions(User $actor, string $reservationId, string $propertyId, array $blockers, array $housekeeping, array $engineering): array
    {
        $frontDesk = $this->frontDeskEvidence($reservationId, $propertyId);
        $status = $frontDesk['status'] ?? null;
        $housekeepingReady = in_array($housekeeping['readiness_state'] ?? null, ['ready_for_arrival', 'ready_for_sale', 'ready_for_vip'], true);
        $engineeringReady = ($engineering['state'] ?? null) === EngineeringRoomAvailabilityProjectionService::AVAILABLE;

        return [
            'can_assign_room' => $blockers === []
                && $frontDesk === null
                && $housekeepingReady
                && $engineeringReady
                && $actor->can('frontdesk.room-assignment.create'),
            'can_prepare_check_in' => $blockers === []
                && $housekeepingReady
                && $engineeringReady
                && in_array($status, [FrontDeskStayStatusEnum::RoomAssigned->value, FrontDeskStayStatusEnum::CheckInConfirmationPending->value], true)
                && $actor->can('frontdesk.check-in.execute'),
            'can_view_in_house' => $status === FrontDeskStayStatusEnum::InHouse->value
                && $actor->can('frontdesk.in-house.view'),
        ];
    }
}

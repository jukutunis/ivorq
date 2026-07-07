<?php

namespace Modules\Operations\FrontDesk\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
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

        $today = Carbon::now($property->timezone ?: config('app.timezone'))->toDateString();
        $search = trim((string) ($filters['search'] ?? ''));
        $date = trim((string) ($filters['arrival_date'] ?? ''));

        $reservations = $this->reservations($propertyId, $search, $date)->map(function (Reservation $reservation) use ($propertyId, $today) {
            return $this->projectReservation($reservation, $propertyId, $today);
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
    private function projectReservation(Reservation $reservation, string $propertyId, string $today): array
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
            'housekeeping' => $this->housekeepingEvidence($room),
            'engineering' => $this->roomBlockEvidence($room?->id, $propertyId),
            'eligibility' => [
                'eligible' => $blockers === [],
                'state' => $blockers === [] ? 'ARRIVAL_READY' : 'BLOCKED',
                'blockers' => $blockers,
            ],
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
    private function roomBlockEvidence(?string $roomId, string $propertyId): array
    {
        if ($roomId === null) {
            return [
                'state' => 'NOT_EVALUATED_NO_ROOM_ASSIGNED',
                'source' => 'PMS RoomBlock',
                'active_block_count' => 0,
            ];
        }

        $now = now();
        $count = DB::table('room_blocks')
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', $now);
            })
            ->count();

        return [
            'state' => $count > 0 ? 'BLOCKED_BY_ACTIVE_ROOM_BLOCK' : 'NO_ACTIVE_ROOM_BLOCK',
            'source' => 'PMS RoomBlock',
            'active_block_count' => $count,
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
}

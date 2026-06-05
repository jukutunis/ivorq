<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Exceptions\NotFoundException;

class ReservationRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Reservation::with(['primaryGuest', 'ratePlan', 'assignedRoom'])
            ->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['reservation_source'])) {
            $query->where('reservation_source', $filters['reservation_source']);
        }

        if (! empty($filters['reserved_room_type'])) {
            $query->where('reserved_room_type', $filters['reserved_room_type']);
        }

        if (! empty($filters['arrival_date'])) {
            $query->whereDate('arrival_date', $filters['arrival_date']);
        }

        if (! empty($filters['departure_date'])) {
            $query->whereDate('departure_date', $filters['departure_date']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): Reservation
    {
        $reservation = Reservation::with([
            'primaryGuest',
            'guests',
            'ratePlan',
            'assignedRoom',
            'stays.room',
            'folios.items',
        ])->find($id);

        throw_if(! $reservation, new NotFoundException('Reservation'));

        return $reservation;
    }

    public function findOrFail(string $id): Reservation
    {
        return Reservation::findOrFail($id);
    }

    public function create(array $data): Reservation
    {
        return Reservation::create($data)->fresh();
    }

    public function update(string $id, array $data): Reservation
    {
        $reservation = $this->find($id);
        $reservation->update($data);

        return $reservation->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    // ── Specialised queries ──────────────────────────────────────────────────

    /**
     * All reservations that overlap with the given date range for a room type.
     * Used by AvailabilityService to calculate inventory.
     * Statuses included: tentative, confirmed, checked_in (avoid double-counting).
     */
    public function activeForDateRange(
        string $roomType,
        string $arrivalDate,
        string $departureDate
    ): Collection {
        return Reservation::where('reserved_room_type', $roomType)
            ->whereIn('status', [
                ReservationStatusEnum::Tentative->value,
                ReservationStatusEnum::Confirmed->value,
                ReservationStatusEnum::CheckedIn->value,
            ])
            ->where('arrival_date', '<', $departureDate)
            ->where('departure_date', '>', $arrivalDate)
            ->get();
    }

    public function byStatus(ReservationStatusEnum $status): Collection
    {
        return Reservation::where('status', $status)
            ->with(['primaryGuest', 'ratePlan', 'assignedRoom'])
            ->latest()
            ->get();
    }

    public function arrivalsToday(): Collection
    {
        return Reservation::whereDate('arrival_date', today())
            ->whereIn('status', [
                ReservationStatusEnum::Confirmed->value,
                ReservationStatusEnum::CheckedIn->value,
            ])
            ->with(['primaryGuest', 'ratePlan', 'assignedRoom'])
            ->orderBy('arrival_date')
            ->get();
    }

    public function departuresToday(): Collection
    {
        return Reservation::whereDate('departure_date', today())
            ->where('status', ReservationStatusEnum::CheckedIn->value)
            ->with(['primaryGuest', 'assignedRoom', 'stays'])
            ->orderBy('departure_date')
            ->get();
    }
}

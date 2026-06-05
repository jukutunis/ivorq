<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Models\Stay;
use Shared\Exceptions\NotFoundException;

class StayRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Stay::with(['reservation.primaryGuest', 'room', 'guest'])
            ->latest('check_in_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (! empty($filters['guest_id'])) {
            $query->where('guest_id', $filters['guest_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): Stay
    {
        $stay = Stay::with([
            'reservation.primaryGuest',
            'reservation.ratePlan',
            'room',
            'guest',
        ])->find($id);

        throw_if(! $stay, new NotFoundException('Stay'));

        return $stay;
    }

    public function findOrFail(string $id): Stay
    {
        return Stay::findOrFail($id);
    }

    public function create(array $data): Stay
    {
        return Stay::create($data)->fresh();
    }

    public function update(string $id, array $data): Stay
    {
        $stay = $this->find($id);
        $stay->update($data);

        return $stay->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    // ── Specialised queries ──────────────────────────────────────────────────

    /**
     * Return the active (reserved or checked_in) stay for a specific room.
     * Used by check-in guard to prevent double-occupancy.
     */
    public function activeForRoom(string $roomId): ?Stay
    {
        return Stay::where('room_id', $roomId)
            ->whereIn('status', [
                StayStatusEnum::Reserved->value,
                StayStatusEnum::CheckedIn->value,
            ])
            ->with(['reservation.primaryGuest', 'guest'])
            ->first();
    }

    /**
     * All currently checked-in stays (in-house guests).
     */
    public function inHouse(): Collection
    {
        return Stay::where('status', StayStatusEnum::CheckedIn)
            ->with(['reservation.primaryGuest', 'room', 'guest'])
            ->orderBy('expected_departure_at')
            ->get();
    }

    /**
     * Stays expected to depart today that are still checked in.
     */
    public function departuresToday(): Collection
    {
        return Stay::where('status', StayStatusEnum::CheckedIn)
            ->whereDate('expected_departure_at', today())
            ->with(['reservation.primaryGuest', 'room', 'guest'])
            ->orderBy('expected_departure_at')
            ->get();
    }
}

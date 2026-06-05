<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Shared\Exceptions\NotFoundException;

class FolioRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Folio::with(['reservation.primaryGuest', 'guest'])
            ->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['guest_id'])) {
            $query->where('guest_id', $filters['guest_id']);
        }

        if (! empty($filters['reservation_id'])) {
            $query->where('reservation_id', $filters['reservation_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): Folio
    {
        $folio = Folio::with([
            'reservation.primaryGuest',
            'guest',
            'items.postedBy',
        ])->find($id);

        throw_if(! $folio, new NotFoundException('Folio'));

        return $folio;
    }

    public function findOrFail(string $id): Folio
    {
        return Folio::findOrFail($id);
    }

    public function create(array $data): Folio
    {
        return Folio::create($data)->fresh();
    }

    public function update(string $id, array $data): Folio
    {
        $folio = $this->find($id);
        $folio->update($data);

        return $folio->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    // ── Specialised queries ──────────────────────────────────────────────────

    /**
     * Return the open folio(s) for a given reservation.
     * Typically one per reservation but supports split-folio scenarios.
     */
    public function openForReservation(string $reservationId): Collection
    {
        return Folio::where('reservation_id', $reservationId)
            ->where('status', FolioStatusEnum::Open)
            ->with(['items.postedBy'])
            ->get();
    }

    /**
     * Return all open folios for a given guest across reservations.
     */
    public function openForGuest(string $guestId): Collection
    {
        return Folio::where('guest_id', $guestId)
            ->where('status', FolioStatusEnum::Open)
            ->with(['reservation', 'items.postedBy'])
            ->latest()
            ->get();
    }
}

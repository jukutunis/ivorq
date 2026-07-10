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

    /**
     * Controlled folio creation.
     *
     * All aggregate-owned fields are server-resolved. Uses forceFill()
     * because the Folio model denies generic mass assignment.
     *
     * @internal Called only by GuestLedgerFolioAggregateService.
     */
    public function createControlled(array $serverResolvedAttributes): Folio
    {
        $folio = new Folio();
        $folio->forceFill($serverResolvedAttributes)->save();

        return $folio->fresh();
    }

    /**
     * Lock a folio row FOR UPDATE within the current property.
     *
     * @internal For recalculation and posting paths.
     */
    public function lockForUpdate(string $id, string $propertyId): Folio
    {
        $folio = Folio::withoutGlobalScope('property')
            ->where('id', $id)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        throw_if(! $folio, new NotFoundException('Folio'));

        return $folio;
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    // ── Specialised queries ──────────────────────────────────────────────────

    /**
     * Return the open folio(s) for a given reservation, ordered by window_number.
     */
    public function openForReservation(string $reservationId): Collection
    {
        return Folio::where('reservation_id', $reservationId)
            ->where('status', FolioStatusEnum::Open)
            ->with(['items.postedBy'])
            ->orderBy('window_number')
            ->get();
    }

    /**
     * Return ALL folios for a given reservation, ordered by window number.
     */
    public function forReservation(string $reservationId): Collection
    {
        return Folio::where('reservation_id', $reservationId)
            ->with(['items.postedBy'])
            ->orderBy('window_number')
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

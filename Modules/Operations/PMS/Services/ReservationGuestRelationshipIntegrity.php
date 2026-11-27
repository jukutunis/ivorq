<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Support\Facades\DB;

/** Validate retained PMS guest identity after the Property and Reservation locks. */
class ReservationGuestRelationshipIntegrity
{
    public function assertConsistent(string $propertyId, string $reservationId, ?string $primaryGuestId): void
    {
        abort_if($primaryGuestId === null || $primaryGuestId === '', 409,
            'Reservation primary guest is unavailable.');

        $pivots = DB::table('reservation_guests')->where('reservation_id', $reservationId)
            ->orderBy('id')->lockForUpdate()->get();
        abort_if($pivots->contains(fn ($pivot) => $pivot->property_id !== $propertyId), 409,
            'Reservation guest relationship has an invalid Property.');

        $primaryPivots = $pivots->filter(fn ($pivot) => (bool) $pivot->is_primary);
        // Older reservations may omit a primary pivot even when accompanying
        // links exist. Any recorded primary must agree with the reservation.
        abort_if($primaryPivots->count() > 1 || ($primaryPivots->isNotEmpty()
            && $primaryPivots->first()->guest_id !== $primaryGuestId), 409,
            'Reservation primary guest relationship is inconsistent.');

        $guestIds = $pivots->pluck('guest_id')->push($primaryGuestId)->unique()->sort()->values();
        $guests = DB::table('guests')->whereIn('id', $guestIds->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($guestIds as $guestId) {
            $guest = $guests->get($guestId);
            abort_if(! $guest || $guest->property_id !== $propertyId || $guest->deleted_at !== null, 409,
                'Reservation refers to a missing, deleted, or foreign guest.');
        }
    }
}

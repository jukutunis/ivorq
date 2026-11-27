<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.reservation.view');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('pms.reservation.view')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $reservation->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pms.reservation.create');
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('pms.reservation.edit')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $reservation->property_id;
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('pms.reservation.delete')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $reservation->property_id;
    }

    /**
     * Covers confirm, cancel, and noShow status transitions.
     * Enforcement of which transitions are valid is delegated to ReservationStatusEnum.
     */
    public function changeStatus(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('pms.reservation.edit')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $reservation->property_id;
    }

    public function checkIn(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('pms.reservation.checkin')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $reservation->property_id;
    }

    public function checkOut(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('pms.reservation.checkout')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $reservation->property_id;
    }
}

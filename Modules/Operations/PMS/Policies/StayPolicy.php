<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\Stay;

class StayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.reservation.view');
    }

    public function view(User $user, Stay $stay): bool
    {
        return $user->hasPermissionTo('pms.reservation.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $stay->property_id);
    }

    /**
     * Check-out is the write operation on an existing stay.
     * The check-in permission lives on ReservationPolicy since it is
     * initiated from a Reservation, not from an existing Stay.
     */
    public function checkOut(User $user, Stay $stay): bool
    {
        return $user->hasPermissionTo('pms.reservation.checkout')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $stay->property_id);
    }
}

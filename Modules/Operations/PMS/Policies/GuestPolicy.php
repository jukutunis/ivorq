<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\Guest;

class GuestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.guest.view');
    }

    public function view(User $user, Guest $guest): bool
    {
        return $user->hasPermissionTo('pms.guest.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $guest->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pms.guest.create');
    }

    public function update(User $user, Guest $guest): bool
    {
        return $user->hasPermissionTo('pms.guest.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $guest->property_id);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $user->hasPermissionTo('pms.guest.delete')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $guest->property_id);
    }
}

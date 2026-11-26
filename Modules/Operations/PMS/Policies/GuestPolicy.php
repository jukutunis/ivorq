<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\Guest;
use Shared\Services\CurrentPropertyService;

class GuestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.guest.view');
    }

    public function view(User $user, Guest $guest): bool
    {
        return $user->hasPermissionTo('pms.guest.view')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $guest->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pms.guest.create');
    }

    public function update(User $user, Guest $guest): bool
    {
        return $user->hasPermissionTo('pms.guest.edit')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $guest->property_id;
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $user->hasPermissionTo('pms.guest.delete')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $guest->property_id;
    }
}

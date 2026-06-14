<?php

namespace Modules\Operations\Zoning\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Zoning\Models\Zone;

class ZonePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('zone.view');
    }

    public function view(User $user, Zone $zone): bool
    {
        return $user->hasPermissionTo('zone.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $zone->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('zone.create');
    }

    public function update(User $user, Zone $zone): bool
    {
        return $user->hasPermissionTo('zone.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $zone->property_id);
    }

    public function delete(User $user, Zone $zone): bool
    {
        return $user->hasPermissionTo('zone.delete')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $zone->property_id);
    }

    public function changeStatus(User $user, Zone $zone): bool
    {
        return $user->hasPermissionTo('zone.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $zone->property_id);
    }

    public function archive(User $user, Zone $zone): bool
    {
        return $user->hasPermissionTo('zone.archive')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $zone->property_id);
    }
}

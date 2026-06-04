<?php

namespace Modules\Operations\Zoning\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class ZoneAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('zone.view');
    }

    public function view(User $user, ZoneAssignment $assignment): bool
    {
        return $user->hasPermissionTo('zone.view')
            && ($user->isSuperAdmin() || $user->property_id === $assignment->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('zone.assign');
    }

    public function update(User $user, ZoneAssignment $assignment): bool
    {
        return $user->hasPermissionTo('zone.assign')
            && ($user->isSuperAdmin() || $user->property_id === $assignment->property_id);
    }

    public function delete(User $user, ZoneAssignment $assignment): bool
    {
        return $user->hasPermissionTo('zone.assign')
            && ($user->isSuperAdmin() || $user->property_id === $assignment->property_id);
    }
}

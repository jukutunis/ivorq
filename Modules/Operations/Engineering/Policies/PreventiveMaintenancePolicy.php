<?php

namespace Modules\Operations\Engineering\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;

class PreventiveMaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('engineering.pm.view');
    }

    public function view(User $user, PreventiveMaintenance $pm): bool
    {
        return $user->hasPermissionTo('engineering.pm.view')
            && ($user->isSuperAdmin() || $user->property_id === $pm->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('engineering.pm.create');
    }

    public function update(User $user, PreventiveMaintenance $pm): bool
    {
        return $user->hasPermissionTo('engineering.pm.edit')
            && ($user->isSuperAdmin() || $user->property_id === $pm->property_id);
    }

    public function delete(User $user, PreventiveMaintenance $pm): bool
    {
        return $user->hasPermissionTo('engineering.pm.delete')
            && ($user->isSuperAdmin() || $user->property_id === $pm->property_id);
    }

    /**
     * Generating a PM task instance requires the same authorisation as editing
     * the PM program — it advances the schedule and creates a child record.
     */
    public function generateTask(User $user, PreventiveMaintenance $pm): bool
    {
        return $user->hasPermissionTo('engineering.pm.edit')
            && ($user->isSuperAdmin() || $user->property_id === $pm->property_id);
    }
}

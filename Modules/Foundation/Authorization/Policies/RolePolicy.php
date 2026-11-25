<?php

namespace Modules\Foundation\Authorization\Policies;

use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\User\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('role.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('role.create');
    }

    public function update(User $user, ?Role $role = null): bool
    {
        return $role !== null
            && $role->property_id !== null
            && $user->hasPermissionTo('role.edit');
    }

    public function delete(User $user, ?Role $role = null): bool
    {
        return $role !== null
            && $role->property_id !== null
            && $user->hasPermissionTo('role.delete');
    }
}

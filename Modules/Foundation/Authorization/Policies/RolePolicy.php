<?php

namespace Modules\Foundation\Authorization\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Role;

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
        return $user->hasPermissionTo('role.edit');
    }

    public function delete(User $user, ?Role $role = null): bool
    {
        return $user->hasPermissionTo('role.delete');
    }
}

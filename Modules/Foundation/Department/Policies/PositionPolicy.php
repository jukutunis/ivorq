<?php

namespace Modules\Foundation\Department\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\User\Models\User;

class PositionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('department.view') || $user->is_system_admin;
    }

    public function view(User $user, Position $position): bool
    {
        return $user->hasPermissionTo('department.view') || $user->is_system_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_system_admin;
    }

    public function update(User $user, Position $position): bool
    {
        return $user->is_system_admin;
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->is_system_admin;
    }
}

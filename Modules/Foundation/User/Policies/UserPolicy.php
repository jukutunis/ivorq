<?php

namespace Modules\Foundation\User\Policies;

use Modules\Foundation\User\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('user.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('user.view')
            && ($user->isSuperAdmin() || $user->property_id === $model->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('user.create');
    }

    public function update(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $user->hasPermissionTo('user.edit')
            && ($user->isSuperAdmin() || $user->property_id === $model->property_id);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasPermissionTo('user.delete')
            && $user->id !== $model->id
            && ($user->isSuperAdmin() || $user->property_id === $model->property_id);
    }
}

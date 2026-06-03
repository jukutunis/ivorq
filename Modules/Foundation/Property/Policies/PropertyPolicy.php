<?php

namespace Modules\Foundation\Property\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('property.view');
    }

    public function view(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('property.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('property.create');
    }

    public function update(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('property.edit');
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('property.delete');
    }
}

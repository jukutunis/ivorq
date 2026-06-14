<?php

namespace Modules\Foundation\Department\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Foundation\Department\Models\Department;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('department.view');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->hasPermissionTo('department.view')
            && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $department->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('department.create');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermissionTo('department.edit')
            && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $department->property_id;
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermissionTo('department.delete')
            && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $department->property_id;
    }
}

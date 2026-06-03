<?php

namespace Modules\Foundation\Property\Policies;

use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\User\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('property.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('property.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('property.create');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('property.edit');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('property.delete');
    }
}

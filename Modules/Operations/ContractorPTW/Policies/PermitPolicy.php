<?php

namespace Modules\Operations\ContractorPTW\Policies;

use App\Models\User;

class PermitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_permits');
    }
}

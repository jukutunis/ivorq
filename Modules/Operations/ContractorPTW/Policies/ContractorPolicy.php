<?php

namespace Modules\Operations\ContractorPTW\Policies;

use App\Models\User;

class ContractorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_contractors');
    }
}

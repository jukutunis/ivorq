<?php

namespace Modules\Finance\Payables\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\Payables\Models\AccountPayable;
use Modules\Foundation\User\Models\User;

class AccountPayablePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payables.ap.view');
    }

    public function view(User $user, AccountPayable $ap): bool
    {
        return $user->hasPermissionTo('payables.ap.view') && 
               $user->hasPropertyAccess($ap->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payables.ap.create');
    }
}

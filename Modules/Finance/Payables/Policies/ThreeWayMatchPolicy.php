<?php

namespace Modules\Finance\Payables\Policies;

use App\Models\User;
use Modules\Finance\Payables\Models\ThreeWayMatch;
use Illuminate\Auth\Access\HandlesAuthorization;

class ThreeWayMatchPolicy
{
    use HandlesAuthorization;

    public function view(User $user, ThreeWayMatch $match): bool
    {
        return $user->hasPermissionTo('payables.match.view') && 
               $user->property_id === $match->property_id;
    }
}

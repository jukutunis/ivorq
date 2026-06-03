<?php

namespace Modules\Foundation\Activity\Policies;

use Modules\Foundation\User\Models\User;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('activity.view');
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo('activity.view');
    }
}

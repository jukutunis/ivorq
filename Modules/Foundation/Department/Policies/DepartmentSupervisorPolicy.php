<?php

namespace Modules\Foundation\Department\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Foundation\Department\Models\DepartmentSupervisor;
use Illuminate\Auth\Access\HandlesAuthorization;

class DepartmentSupervisorPolicy
{
    use HandlesAuthorization;

    public function manage(User $user, User $targetUser): bool
    {
        return $user->can('department.supervisors.manage')
            && $user->id !== $targetUser->id;
    }

    public function manageAssignment(User $user, DepartmentSupervisor $assignment): bool
    {
        return $user->can('department.supervisors.manage')
            && $user->id !== $assignment->user_id;
    }
}

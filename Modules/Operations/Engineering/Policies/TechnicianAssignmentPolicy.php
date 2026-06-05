<?php

namespace Modules\Operations\Engineering\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Models\TechnicianAssignment;

class TechnicianAssignmentPolicy
{
    public function view(User $user, TechnicianAssignment $assignment): bool
    {
        return $user->hasPermissionTo('engineering.work-order.view')
            && ($user->isSuperAdmin() || $user->property_id === $assignment->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('engineering.work-order.assign');
    }

    public function update(User $user, TechnicianAssignment $assignment): bool
    {
        return $user->hasPermissionTo('engineering.work-order.assign')
            && ($user->isSuperAdmin() || $user->property_id === $assignment->property_id);
    }

    public function delete(User $user, TechnicianAssignment $assignment): bool
    {
        return $user->hasPermissionTo('engineering.work-order.assign')
            && ($user->isSuperAdmin() || $user->property_id === $assignment->property_id);
    }
}

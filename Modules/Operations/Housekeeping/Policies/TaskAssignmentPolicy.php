<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\TaskAssignment;

class TaskAssignmentPolicy
{
    public function view(User $user, TaskAssignment $assignment): bool
    {
        return $user->hasPermissionTo('housekeeping.task.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $assignment->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.task.assign');
    }

    public function update(User $user, TaskAssignment $assignment): bool
    {
        return $user->hasPermissionTo('housekeeping.task.assign')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $assignment->property_id);
    }

    public function delete(User $user, TaskAssignment $assignment): bool
    {
        return $user->hasPermissionTo('housekeeping.task.assign')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $assignment->property_id);
    }
}

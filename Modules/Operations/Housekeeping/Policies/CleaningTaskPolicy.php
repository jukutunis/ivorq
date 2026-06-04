<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\CleaningTask;

class CleaningTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.task.view');
    }

    public function view(User $user, CleaningTask $task): bool
    {
        return $user->hasPermissionTo('housekeeping.task.view')
            && ($user->isSuperAdmin() || $user->property_id === $task->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.task.create');
    }

    public function update(User $user, CleaningTask $task): bool
    {
        return $user->hasPermissionTo('housekeeping.task.edit')
            && ($user->isSuperAdmin() || $user->property_id === $task->property_id);
    }

    public function delete(User $user, CleaningTask $task): bool
    {
        return $user->hasPermissionTo('housekeeping.task.delete')
            && ($user->isSuperAdmin() || $user->property_id === $task->property_id);
    }

    public function assign(User $user, CleaningTask $task): bool
    {
        return $user->hasPermissionTo('housekeeping.task.assign')
            && ($user->isSuperAdmin() || $user->property_id === $task->property_id);
    }

    public function changeStatus(User $user, CleaningTask $task): bool
    {
        return $user->hasAnyPermission([
            'housekeeping.task.edit',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.task.cancel',
        ]) && ($user->isSuperAdmin() || $user->property_id === $task->property_id);
    }
}

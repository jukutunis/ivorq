<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\CleaningTask;

class CleaningTaskPolicy
{
    public function viewAny(User $user): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.task.view')
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    public function view(User $user, CleaningTask $task): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.task.view')
            && ($user->isSuperAdmin() || ($propertyId === $task->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function create(User $user): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.task.create')
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    public function update(User $user, CleaningTask $task): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        if ($task->status === \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::Completed) {
            return false;
        }
        return $user->hasPermissionTo('housekeeping.task.edit')
            && ($user->isSuperAdmin() || ($propertyId === $task->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function delete(User $user, CleaningTask $task): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        if ($task->status === \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::Completed) {
            return false;
        }
        return $user->hasPermissionTo('housekeeping.task.delete')
            && ($user->isSuperAdmin() || ($propertyId === $task->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function assign(User $user, CleaningTask $task): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.task.assign')
            && ($user->isSuperAdmin() || ($propertyId === $task->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function changeStatus(User $user, CleaningTask $task): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasAnyPermission([
            'housekeeping.task.edit',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.task.cancel',
        ]) && ($user->isSuperAdmin() || ($propertyId === $task->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }
}

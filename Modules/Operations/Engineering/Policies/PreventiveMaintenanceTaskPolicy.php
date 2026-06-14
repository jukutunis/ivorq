<?php

namespace Modules\Operations\Engineering\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;

class PreventiveMaintenanceTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('engineering.pm.view');
    }

    public function view(User $user, PreventiveMaintenanceTask $task): bool
    {
        return $user->hasPermissionTo('engineering.pm.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $task->property_id);
    }

    public function changeStatus(User $user, PreventiveMaintenanceTask $task): bool
    {
        return $user->hasPermissionTo('engineering.pm.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $task->property_id);
    }

    public function createWorkOrder(User $user, PreventiveMaintenanceTask $task): bool
    {
        return $user->hasPermissionTo('engineering.work-order.create')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $task->property_id);
    }
}

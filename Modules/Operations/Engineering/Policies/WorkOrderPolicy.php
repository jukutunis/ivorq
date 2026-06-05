<?php

namespace Modules\Operations\Engineering\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('engineering.work-order.view');
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionTo('engineering.work-order.view')
            && ($user->isSuperAdmin() || $user->property_id === $workOrder->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('engineering.work-order.create');
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionTo('engineering.work-order.edit')
            && ($user->isSuperAdmin() || $user->property_id === $workOrder->property_id);
    }

    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionTo('engineering.work-order.delete')
            && ($user->isSuperAdmin() || $user->property_id === $workOrder->property_id);
    }

    public function assign(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionTo('engineering.work-order.assign')
            && ($user->isSuperAdmin() || $user->property_id === $workOrder->property_id);
    }

    public function approve(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionTo('engineering.work-order.approve')
            && ($user->isSuperAdmin() || $user->property_id === $workOrder->property_id);
    }

    /**
     * changeStatus covers start, on-hold, complete, and cancel actions.
     * A single edit permission guards all status transitions; enforcement
     * of which transitions are valid is delegated to WorkOrderStatusEnum.
     */
    public function changeStatus(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionTo('engineering.work-order.edit')
            && ($user->isSuperAdmin() || $user->property_id === $workOrder->property_id);
    }
}

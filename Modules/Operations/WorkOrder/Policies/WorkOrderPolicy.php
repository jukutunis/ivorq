<?php

namespace Modules\Operations\WorkOrder\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $user->can('workorder.view');
    }

    public function view(User $user, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->can('workorder.view') &&
               !empty($resolvedPropertyId) &&
               $resolvedPropertyId === $workOrder->property_id;
    }

    public function create(User $user)
    {
        return $user->can('workorder.create');
    }

    public function update(User $user, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->can('workorder.update') &&
               !empty($resolvedPropertyId) &&
               $resolvedPropertyId === $workOrder->property_id;
    }

    public function assign(User $user, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->can('workorder.assign') &&
               !empty($resolvedPropertyId) &&
               $resolvedPropertyId === $workOrder->property_id;
    }

    public function delete(User $user, WorkOrder $workOrder)
    {
        return false; // No hard deletes
    }
}

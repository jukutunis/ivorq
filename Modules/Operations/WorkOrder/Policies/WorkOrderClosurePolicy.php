<?php

namespace Modules\Operations\WorkOrder\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\WorkOrder\Models\WorkOrderClosure;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkOrderClosurePolicy
{
    use HandlesAuthorization;

    public function create(User $user, WorkOrder $workOrder)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->can('workorder.close') &&
               !empty($resolvedPropertyId) &&
               $resolvedPropertyId === $workOrder->property_id;
    }

    public function view(User $user, WorkOrderClosure $closure)
    {
        $resolvedPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->can('workorder.view') &&
               !empty($resolvedPropertyId) &&
               $resolvedPropertyId === $closure->workOrder->property_id;
    }
}

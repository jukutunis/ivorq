<?php

namespace Modules\Operations\WorkOrder\Policies;

use App\Models\User;
use Modules\Operations\WorkOrder\Models\WorkOrderClosure;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkOrderClosurePolicy
{
    use HandlesAuthorization;

    public function create(User $user)
    {
        return $user->can('workorder.close');
    }

    public function view(User $user, WorkOrderClosure $closure)
    {
        return $user->can('workorder.view') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $closure->workOrder->property_id;
    }
}

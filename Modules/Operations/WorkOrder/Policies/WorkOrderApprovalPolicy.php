<?php

namespace Modules\Operations\WorkOrder\Policies;

use App\Models\User;
use Modules\Operations\WorkOrder\Models\WorkOrderApproval;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkOrderApprovalPolicy
{
    use HandlesAuthorization;

    public function view(User $user, WorkOrderApproval $approval)
    {
        return $user->can('workorder.view') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $approval->workOrder->property_id;
    }

    public function create(User $user)
    {
        return $user->can('workorder.approve');
    }

    public function update(User $user, WorkOrderApproval $approval)
    {
        return $user->id === $approval->approver_id || $user->can('workorder.manage');
    }
}

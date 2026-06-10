<?php

namespace Modules\Foundation\Approval\Policies;

use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\User\Models\User;

class ApprovalWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('foundation.approval-workflow.view');
    }

    public function view(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->hasPermissionTo('foundation.approval-workflow.view')
            && ($user->isSuperAdmin() || $user->property_id === $approvalWorkflow->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('foundation.approval-workflow.create');
    }

    public function update(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->hasPermissionTo('foundation.approval-workflow.edit')
            && ($user->isSuperAdmin() || $user->property_id === $approvalWorkflow->property_id);
    }

    public function delete(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->hasPermissionTo('foundation.approval-workflow.delete')
            && ($user->isSuperAdmin() || $user->property_id === $approvalWorkflow->property_id);
    }
}

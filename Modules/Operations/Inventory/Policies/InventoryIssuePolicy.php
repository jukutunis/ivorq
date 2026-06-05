<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryIssue;

class InventoryIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.issue.view');
    }

    public function view(User $user, InventoryIssue $issue): bool
    {
        return $user->hasPermissionTo('inventory.issue.view')
            && ($user->isSuperAdmin() || $user->property_id === $issue->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.issue.create');
    }

    public function update(User $user, InventoryIssue $issue): bool
    {
        return $user->hasPermissionTo('inventory.issue.edit')
            && ($user->isSuperAdmin() || $user->property_id === $issue->property_id);
    }

    public function delete(User $user, InventoryIssue $issue): bool
    {
        return $user->hasPermissionTo('inventory.issue.delete')
            && ($user->isSuperAdmin() || $user->property_id === $issue->property_id);
    }

    /**
     * Posting an issue deducts stock (BR-042).
     * Separate post permission lets managers restrict who can finalise issues.
     */
    public function post(User $user, InventoryIssue $issue): bool
    {
        return $user->hasPermissionTo('inventory.issue.post')
            && ($user->isSuperAdmin() || $user->property_id === $issue->property_id);
    }

    /**
     * Cancellation only allowed from Draft (BR-043).
     */
    public function cancel(User $user, InventoryIssue $issue): bool
    {
        return $user->hasPermissionTo('inventory.issue.edit')
            && ($user->isSuperAdmin() || $user->property_id === $issue->property_id);
    }
}

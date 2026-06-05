<?php

namespace Modules\Operations\Engineering\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Models\EngineeringChecklist;

class EngineeringChecklistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('engineering.checklist.view');
    }

    public function view(User $user, EngineeringChecklist $checklist): bool
    {
        return $user->hasPermissionTo('engineering.checklist.view')
            && ($user->isSuperAdmin() || $user->property_id === $checklist->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('engineering.checklist.create');
    }

    public function update(User $user, EngineeringChecklist $checklist): bool
    {
        return $user->hasPermissionTo('engineering.checklist.edit')
            && ($user->isSuperAdmin() || $user->property_id === $checklist->property_id);
    }

    public function delete(User $user, EngineeringChecklist $checklist): bool
    {
        return $user->hasPermissionTo('engineering.checklist.delete')
            && ($user->isSuperAdmin() || $user->property_id === $checklist->property_id);
    }
}

<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;

class ChecklistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.checklist.view');
    }

    public function view(User $user, CleaningChecklist $checklist): bool
    {
        return $user->hasPermissionTo('housekeeping.checklist.view')
            && ($user->isSuperAdmin() || $user->property_id === $checklist->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.checklist.create');
    }

    public function update(User $user, CleaningChecklist $checklist): bool
    {
        return $user->hasPermissionTo('housekeeping.checklist.edit')
            && ($user->isSuperAdmin() || $user->property_id === $checklist->property_id);
    }

    public function delete(User $user, CleaningChecklist $checklist): bool
    {
        return $user->hasPermissionTo('housekeeping.checklist.delete')
            && ($user->isSuperAdmin() || $user->property_id === $checklist->property_id);
    }
}

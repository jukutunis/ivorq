<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\RoomInspection;

class RoomInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.inspection.view');
    }

    public function view(User $user, RoomInspection $inspection): bool
    {
        return $user->hasPermissionTo('housekeeping.inspection.view')
            && ($user->isSuperAdmin() || $user->property_id === $inspection->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.inspection.create');
    }

    public function update(User $user, RoomInspection $inspection): bool
    {
        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || $user->property_id === $inspection->property_id);
    }

    public function delete(User $user, RoomInspection $inspection): bool
    {
        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || $user->property_id === $inspection->property_id);
    }

    public function conduct(User $user, RoomInspection $inspection): bool
    {
        return $user->hasPermissionTo('housekeeping.inspection.conduct')
            && ($user->isSuperAdmin() || $user->property_id === $inspection->property_id);
    }
}

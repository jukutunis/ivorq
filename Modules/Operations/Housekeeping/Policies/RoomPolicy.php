<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\Room;

class RoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.room.view');
    }

    public function view(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('housekeeping.room.view')
            && ($user->isSuperAdmin() || $user->property_id === $room->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('housekeeping.room.create');
    }

    public function update(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('housekeeping.room.edit')
            && ($user->isSuperAdmin() || $user->property_id === $room->property_id);
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('housekeeping.room.delete')
            && ($user->isSuperAdmin() || $user->property_id === $room->property_id);
    }

    public function changeStatus(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('housekeeping.room.edit')
            && ($user->isSuperAdmin() || $user->property_id === $room->property_id);
    }

    public function assignZone(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('housekeeping.room.edit')
            && ($user->isSuperAdmin() || $user->property_id === $room->property_id);
    }
}

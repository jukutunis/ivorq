<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\RoomBlock;
use Shared\Services\CurrentPropertyService;

class RoomBlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.room-block.view');
    }

    public function view(User $user, RoomBlock $roomBlock): bool
    {
        return $user->hasPermissionTo('pms.room-block.view')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $roomBlock->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pms.room-block.create');
    }

    public function update(User $user, RoomBlock $roomBlock): bool
    {
        return $user->hasPermissionTo('pms.room-block.edit')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $roomBlock->property_id;
    }

    public function delete(User $user, RoomBlock $roomBlock): bool
    {
        return $user->hasPermissionTo('pms.room-block.delete')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $roomBlock->property_id;
    }

    /**
     * Covers both release and expire transitions.
     */
    public function changeStatus(User $user, RoomBlock $roomBlock): bool
    {
        return $user->hasPermissionTo('pms.room-block.edit')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $roomBlock->property_id;
    }
}

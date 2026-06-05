<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryLocation;

class InventoryLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.location.view');
    }

    public function view(User $user, InventoryLocation $location): bool
    {
        return $user->hasPermissionTo('inventory.location.view')
            && ($user->isSuperAdmin() || $user->property_id === $location->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.location.create');
    }

    public function update(User $user, InventoryLocation $location): bool
    {
        return $user->hasPermissionTo('inventory.location.edit')
            && ($user->isSuperAdmin() || $user->property_id === $location->property_id);
    }

    public function delete(User $user, InventoryLocation $location): bool
    {
        return $user->hasPermissionTo('inventory.location.delete')
            && ($user->isSuperAdmin() || $user->property_id === $location->property_id);
    }
}

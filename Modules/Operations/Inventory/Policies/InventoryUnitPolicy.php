<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryUnit;

class InventoryUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.unit.view');
    }

    public function view(User $user, InventoryUnit $unit): bool
    {
        return $user->hasPermissionTo('inventory.unit.view')
            && ($user->isSuperAdmin() || $user->property_id === $unit->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.unit.create');
    }

    public function update(User $user, InventoryUnit $unit): bool
    {
        return $user->hasPermissionTo('inventory.unit.edit')
            && ($user->isSuperAdmin() || $user->property_id === $unit->property_id);
    }

    public function delete(User $user, InventoryUnit $unit): bool
    {
        return $user->hasPermissionTo('inventory.unit.delete')
            && ($user->isSuperAdmin() || $user->property_id === $unit->property_id);
    }
}

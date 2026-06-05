<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryItem;

class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.item.view');
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return $user->hasPermissionTo('inventory.item.view')
            && ($user->isSuperAdmin() || $user->property_id === $item->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.item.create');
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return $user->hasPermissionTo('inventory.item.edit')
            && ($user->isSuperAdmin() || $user->property_id === $item->property_id);
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return $user->hasPermissionTo('inventory.item.delete')
            && ($user->isSuperAdmin() || $user->property_id === $item->property_id);
    }
}

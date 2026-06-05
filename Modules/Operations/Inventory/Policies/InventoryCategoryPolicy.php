<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;

class InventoryCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.category.view');
    }

    public function view(User $user, InventoryCategory $category): bool
    {
        return $user->hasPermissionTo('inventory.category.view')
            && ($user->isSuperAdmin() || $user->property_id === $category->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.category.create');
    }

    public function update(User $user, InventoryCategory $category): bool
    {
        return $user->hasPermissionTo('inventory.category.edit')
            && ($user->isSuperAdmin() || $user->property_id === $category->property_id);
    }

    public function delete(User $user, InventoryCategory $category): bool
    {
        return $user->hasPermissionTo('inventory.category.delete')
            && ($user->isSuperAdmin() || $user->property_id === $category->property_id);
    }
}

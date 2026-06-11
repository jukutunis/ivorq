<?php

namespace Modules\Operations\Inventory\Policies;

use App\Models\User;

class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_inventory');
    }
}

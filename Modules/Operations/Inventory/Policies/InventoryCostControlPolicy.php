<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;

class InventoryCostControlPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.cost-control.view');
    }
}

<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;

class InventoryLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.ledger.view');
    }
}

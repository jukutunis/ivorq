<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryTransfer;

class InventoryTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.transfer.view');
    }

    public function view(User $user, InventoryTransfer $transfer): bool
    {
        return $user->hasPermissionTo('inventory.transfer.view')
            && ($user->isSuperAdmin() || $user->property_id === $transfer->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.transfer.create');
    }

    public function update(User $user, InventoryTransfer $transfer): bool
    {
        return $user->hasPermissionTo('inventory.transfer.edit')
            && ($user->isSuperAdmin() || $user->property_id === $transfer->property_id);
    }

    public function delete(User $user, InventoryTransfer $transfer): bool
    {
        return $user->hasPermissionTo('inventory.transfer.delete')
            && ($user->isSuperAdmin() || $user->property_id === $transfer->property_id);
    }

    /**
     * Completing a transfer moves stock between locations (BR-053).
     * Separate complete permission so warehouse managers can control finalisation.
     */
    public function complete(User $user, InventoryTransfer $transfer): bool
    {
        return $user->hasPermissionTo('inventory.transfer.complete')
            && ($user->isSuperAdmin() || $user->property_id === $transfer->property_id);
    }

    /**
     * Cancellation allowed from Draft or Submitted (BR-054).
     */
    public function cancel(User $user, InventoryTransfer $transfer): bool
    {
        return $user->hasPermissionTo('inventory.transfer.edit')
            && ($user->isSuperAdmin() || $user->property_id === $transfer->property_id);
    }
}

<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryReceipt;

class InventoryReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.receipt.view');
    }

    public function view(User $user, InventoryReceipt $receipt): bool
    {
        return $user->hasPermissionTo('inventory.receipt.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $receipt->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.receipt.create');
    }

    public function update(User $user, InventoryReceipt $receipt): bool
    {
        return $user->hasPermissionTo('inventory.receipt.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $receipt->property_id);
    }

    public function delete(User $user, InventoryReceipt $receipt): bool
    {
        return $user->hasPermissionTo('inventory.receipt.delete')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $receipt->property_id);
    }

    /**
     * Posting a receipt applies stock and cost changes (BR-032).
     * Requires a dedicated post permission — separate from edit — so
     * managers can restrict who is allowed to finalise movements.
     */
    public function post(User $user, InventoryReceipt $receipt): bool
    {
        return $user->hasPermissionTo('inventory.receipt.post')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $receipt->property_id);
    }

    /**
     * Cancellation only applies to Draft receipts (BR-033).
     * Guarded by edit permission — same actor who can edit can cancel.
     */
    public function cancel(User $user, InventoryReceipt $receipt): bool
    {
        return $user->hasPermissionTo('inventory.receipt.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $receipt->property_id);
    }
}

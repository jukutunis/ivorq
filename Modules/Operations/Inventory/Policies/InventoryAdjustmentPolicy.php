<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryAdjustment;

class InventoryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.view');
    }

    public function view(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.create');
    }

    public function update(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    public function delete(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.delete')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    /**
     * Submitting sends the adjustment for manager review (BR-064).
     * Any user with create permission can submit their own draft.
     */
    public function submit(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.create')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    /**
     * Approving applies stock changes (BR-064, BR-065).
     * Requires the dedicated approve permission — a manager-level gate.
     */
    public function approve(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.approve')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    /**
     * Rejecting is also a manager-level action.
     */
    public function reject(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.approve')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    /**
     * Cancellation by any user with create permission (draft) or approve permission (submitted).
     */
    /**
     * Posting an adjustment applies the confirmed stock change (Sprint 38).
     */
    public function post(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.post')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }

    public function cancel(User $user, InventoryAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('inventory.adjustment.create')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $adjustment->property_id);
    }
}

<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseOrder;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.view');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.view')
            && ($user->isSuperAdmin() || $user->property_id === $purchaseOrder->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.create');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.edit')
            && ($user->isSuperAdmin() || $user->property_id === $purchaseOrder->property_id);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.delete')
            && ($user->isSuperAdmin() || $user->property_id === $purchaseOrder->property_id);
    }

    public function issue(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.issue')
            && ($user->isSuperAdmin() || $user->property_id === $purchaseOrder->property_id);
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.cancel')
            && ($user->isSuperAdmin() || $user->property_id === $purchaseOrder->property_id);
    }
}

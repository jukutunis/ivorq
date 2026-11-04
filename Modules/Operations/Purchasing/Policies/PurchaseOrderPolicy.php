<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Shared\Services\CurrentPropertyService;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.view');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.view')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.create');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.edit')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.delete')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }

    public function issue(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.issue')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.edit')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.approve')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }

    public function reject(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->approve($user, $purchaseOrder);
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-order.cancel')
            && ($user->isSuperAdmin() || app(CurrentPropertyService::class)->getPropertyId() === $purchaseOrder->property_id);
    }
}

<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseRequest;

class PurchaseRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.view');
    }

    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.create');
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id);
    }

    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.delete')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id);
    }

    public function cancel(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.cancel')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id);
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionTo('purchasing.purchase-request.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id);
    }

    public function approve(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id;
    }

    public function reject(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $purchaseRequest->property_id;
    }
}

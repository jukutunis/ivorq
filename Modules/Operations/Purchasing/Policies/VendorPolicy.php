<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.view');
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.view')
            && ($user->isSuperAdmin() || $vendor->property_id === null || $user->property_id === $vendor->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.create');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.edit')
            && ($user->isSuperAdmin() || $vendor->property_id === null || $user->property_id === $vendor->property_id);
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.delete')
            && ($user->isSuperAdmin() || $vendor->property_id === null || $user->property_id === $vendor->property_id);
    }

    public function approve(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.approve')
            && ($user->isSuperAdmin() || $vendor->property_id === null || $user->property_id === $vendor->property_id);
    }
}

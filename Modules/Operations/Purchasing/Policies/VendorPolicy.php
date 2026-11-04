<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.view');
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.view')
            && $this->isInCurrentCompany($user, $vendor);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.create');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.edit')
            && $this->isInCurrentCompany($user, $vendor);
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.delete')
            && $this->isInCurrentCompany($user, $vendor);
    }

    public function approve(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('purchasing.vendor.approve')
            && $this->isInCurrentCompany($user, $vendor);
    }

    private function isInCurrentCompany(User $user, Vendor $vendor): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $propertyId !== null
            && Property::query()->whereKey($propertyId)->where('company_id', $vendor->company_id)->exists();
    }
}

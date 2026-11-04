<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorContact;
use Shared\Services\CurrentPropertyService;

class VendorContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-contact.view');
    }

    public function view(User $user, VendorContact $contact): bool
    {
        $vendor = $contact->vendor;

        return $user->hasPermissionTo('purchasing.vendor-contact.view')
            && $this->isInCurrentCompany($user, $vendor);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-contact.create');
    }

    public function update(User $user, VendorContact $contact): bool
    {
        $vendor = $contact->vendor;

        return $user->hasPermissionTo('purchasing.vendor-contact.edit')
            && $this->isInCurrentCompany($user, $vendor);
    }

    public function delete(User $user, VendorContact $contact): bool
    {
        $vendor = $contact->vendor;

        return $user->hasPermissionTo('purchasing.vendor-contact.delete')
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

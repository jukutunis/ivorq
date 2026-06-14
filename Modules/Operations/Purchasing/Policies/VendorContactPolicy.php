<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\VendorContact;

class VendorContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-contact.view');
    }

    public function view(User $user, VendorContact $contact): bool
    {
        // Depends on the vendor's property
        $vendor = $contact->vendor;
        return $user->hasPermissionTo('purchasing.vendor-contact.view')
            && ($user->isSuperAdmin() || $vendor->property_id === null || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $vendor->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-contact.create');
    }

    public function update(User $user, VendorContact $contact): bool
    {
        $vendor = $contact->vendor;
        return $user->hasPermissionTo('purchasing.vendor-contact.edit')
            && ($user->isSuperAdmin() || $vendor->property_id === null || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $vendor->property_id);
    }

    public function delete(User $user, VendorContact $contact): bool
    {
        $vendor = $contact->vendor;
        return $user->hasPermissionTo('purchasing.vendor-contact.delete')
            && ($user->isSuperAdmin() || $vendor->property_id === null || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $vendor->property_id);
    }
}

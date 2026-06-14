<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\VendorCategory;

class VendorCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-category.view');
    }

    public function view(User $user, VendorCategory $category): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-category.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $category->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-category.create');
    }

    public function update(User $user, VendorCategory $category): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-category.edit')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $category->property_id);
    }

    public function delete(User $user, VendorCategory $category): bool
    {
        return $user->hasPermissionTo('purchasing.vendor-category.delete')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $category->property_id);
    }
}

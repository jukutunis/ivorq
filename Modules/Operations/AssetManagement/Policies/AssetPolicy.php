<?php

namespace Modules\Operations\AssetManagement\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Enums\AssetStatusEnum;
use Illuminate\Auth\Access\HandlesAuthorization;

class AssetPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('asset.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->hasPermissionTo('asset.view') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $asset->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('asset.create');
    }

    public function update(User $user, Asset $asset): bool
    {
        if ($asset->status === AssetStatusEnum::DISPOSED->value || $asset->status === AssetStatusEnum::RETIRED->value) {
            return false; // Disposed/Retired assets are immutable
        }

        return $user->hasPermissionTo('asset.update') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $asset->property_id;
    }

    public function delete(User $user, Asset $asset): bool
    {
        if ($asset->status === AssetStatusEnum::DISPOSED->value || $asset->status === AssetStatusEnum::RETIRED->value) {
            return false; // Already disposed/retired cannot be deleted
        }

        return $user->hasPermissionTo('asset.delete') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $asset->property_id;
    }
}

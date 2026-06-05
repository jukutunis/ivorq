<?php

namespace Modules\Operations\Engineering\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Models\AssetRequest;

class AssetRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.view');
    }

    public function view(User $user, AssetRequest $request): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.view')
            && ($user->isSuperAdmin() || $user->property_id === $request->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.create');
    }

    public function update(User $user, AssetRequest $request): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.edit')
            && ($user->isSuperAdmin() || $user->property_id === $request->property_id);
    }

    public function delete(User $user, AssetRequest $request): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.edit')
            && ($user->isSuperAdmin() || $user->property_id === $request->property_id);
    }

    /**
     * Approve, reject, and fulfill all require the approve permission —
     * these are all manager-level authorisation actions.
     */
    public function approve(User $user, AssetRequest $request): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.approve')
            && ($user->isSuperAdmin() || $user->property_id === $request->property_id);
    }

    public function reject(User $user, AssetRequest $request): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.approve')
            && ($user->isSuperAdmin() || $user->property_id === $request->property_id);
    }

    public function fulfill(User $user, AssetRequest $request): bool
    {
        return $user->hasPermissionTo('engineering.asset-request.approve')
            && ($user->isSuperAdmin() || $user->property_id === $request->property_id);
    }
}

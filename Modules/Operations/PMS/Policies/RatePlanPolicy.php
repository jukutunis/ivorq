<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\RatePlan;

class RatePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.view');
    }

    public function view(User $user, RatePlan $ratePlan): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $ratePlan->property_id);
    }

    /**
     * Covers create, update, and delete of rate plans.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.manage');
    }

    public function update(User $user, RatePlan $ratePlan): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.manage')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $ratePlan->property_id);
    }

    public function delete(User $user, RatePlan $ratePlan): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.manage')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $ratePlan->property_id);
    }
}

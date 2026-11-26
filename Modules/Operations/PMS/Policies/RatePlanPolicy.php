<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\RatePlan;
use Shared\Services\CurrentPropertyService;

class RatePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.view');
    }

    public function view(User $user, RatePlan $ratePlan): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.view')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $ratePlan->property_id;
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
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $ratePlan->property_id;
    }

    public function delete(User $user, RatePlan $ratePlan): bool
    {
        return $user->hasPermissionTo('pms.rate-plan.manage')
            && app(CurrentPropertyService::class)->getExplicitPropertyId() === $ratePlan->property_id;
    }
}

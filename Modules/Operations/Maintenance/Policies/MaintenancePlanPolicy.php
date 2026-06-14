<?php

namespace Modules\Operations\Maintenance\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Illuminate\Auth\Access\HandlesAuthorization;

class MaintenancePlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('maintenance.view');
    }

    public function view(User $user, MaintenancePlan $plan): bool
    {
        return $user->hasPermissionTo('maintenance.view') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $plan->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('maintenance.create');
    }

    public function update(User $user, MaintenancePlan $plan): bool
    {
        return $user->hasPermissionTo('maintenance.update') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $plan->property_id;
    }
}

<?php

namespace Modules\Operations\Maintenance\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Maintenance\Models\MaintenanceExecution;
use Illuminate\Auth\Access\HandlesAuthorization;

class MaintenanceExecutionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('maintenance.view');
    }

    public function view(User $user, MaintenanceExecution $execution): bool
    {
        return $user->hasPermissionTo('maintenance.view') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $execution->property_id;
    }

    public function update(User $user, MaintenanceExecution $execution): bool
    {
        return $user->hasPermissionTo('maintenance.execute') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $execution->property_id;
    }

    public function complete(User $user, MaintenanceExecution $execution): bool
    {
        return $user->hasPermissionTo('maintenance.complete') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $execution->property_id;
    }

    public function cancel(User $user, MaintenanceExecution $execution): bool
    {
        return $user->hasPermissionTo('maintenance.cancel') && app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $execution->property_id;
    }
}

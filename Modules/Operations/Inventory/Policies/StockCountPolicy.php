<?php

namespace Modules\Operations\Inventory\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\StockCountSession;

class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.stock-count.create');
    }

    public function view(User $user, StockCountSession $session): bool
    {
        return $user->hasPermissionTo('inventory.stock-count.create')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $session->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.stock-count.create');
    }

    public function approve(User $user, StockCountSession $session): bool
    {
        return $user->hasPermissionTo('inventory.stock-count.approve')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $session->property_id);
    }

    public function post(User $user, StockCountSession $session): bool
    {
        return $user->hasPermissionTo('inventory.stock-count.post')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $session->property_id);
    }
}

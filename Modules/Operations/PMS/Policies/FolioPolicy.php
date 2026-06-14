<?php

namespace Modules\Operations\PMS\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Models\Folio;

class FolioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('pms.folio.view');
    }

    public function view(User $user, Folio $folio): bool
    {
        return $user->hasPermissionTo('pms.folio.view')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $folio->property_id);
    }

    /**
     * Covers create, post items, void items, close, and void folio actions.
     */
    public function manage(User $user, Folio $folio): bool
    {
        return $user->hasPermissionTo('pms.folio.manage')
            && ($user->isSuperAdmin() || app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $folio->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('pms.folio.manage');
    }
}

<?php

namespace Modules\Operations\Zoning\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Zoning\Models\ZoneTemplate;

class ZoneTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('zone.view');
    }

    public function view(User $user, ZoneTemplate $template): bool
    {
        return $user->hasPermissionTo('zone.view')
            && ($user->isSuperAdmin() || $user->property_id === $template->property_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('zone.create');
    }

    public function update(User $user, ZoneTemplate $template): bool
    {
        return $user->hasPermissionTo('zone.edit')
            && ($user->isSuperAdmin() || $user->property_id === $template->property_id);
    }

    public function delete(User $user, ZoneTemplate $template): bool
    {
        return $user->hasPermissionTo('zone.delete')
            && ($user->isSuperAdmin() || $user->property_id === $template->property_id);
    }
}

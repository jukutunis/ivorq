<?php

namespace Modules\Foundation\Audit\Policies;

use Modules\Foundation\User\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('audit.view');
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo('audit.view');
    }
}

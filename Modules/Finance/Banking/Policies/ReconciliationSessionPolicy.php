<?php

namespace Modules\Finance\Banking\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Foundation\User\Models\User;

class ReconciliationSessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('banking.reconciliation.view');
    }

    public function view(User $user, ReconciliationSession $session): bool
    {
        if ($user->property_id !== $session->property_id && method_exists($user, 'isSuperAdmin') && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('banking.reconciliation.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('banking.reconciliation.create');
    }

    public function manage(User $user, ReconciliationSession $session): bool
    {
        if ($user->property_id !== $session->property_id && method_exists($user, 'isSuperAdmin') && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('banking.reconciliation.manage');
    }
}

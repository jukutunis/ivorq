<?php

namespace Modules\Finance\Banking\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Finance\Banking\Models\BankingMigrationPlan;

class BankingMigrationPlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(?Authenticatable $user): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can('finance.banking.migration.view');
    }

    public function view(?Authenticatable $user, BankingMigrationPlan $plan): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can('finance.banking.migration.view');
    }

    public function create(?Authenticatable $user): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can('finance.banking.migration.manage');
    }

    public function requestDryRun(?Authenticatable $user, BankingMigrationPlan $plan): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can('finance.banking.migration.manage');
    }
}

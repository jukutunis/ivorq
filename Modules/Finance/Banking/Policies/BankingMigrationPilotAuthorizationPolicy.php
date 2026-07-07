<?php

namespace Modules\Finance\Banking\Policies;

use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Foundation\User\Models\User;

class BankingMigrationPilotAuthorizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.banking.migration.view');
    }

    public function view(User $user, BankingMigrationPilotAuthorization $pilotAuthorization): bool
    {
        return $user->can('finance.banking.migration.view');
    }

    public function request(User $user): bool
    {
        return $user->can('finance.banking.migration.manage');
    }

    public function review(User $user, BankingMigrationPilotAuthorization $pilotAuthorization): bool
    {
        return $user->can('finance.banking.migration.pilot.authorization.review');
    }
}

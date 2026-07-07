<?php

namespace Modules\Finance\Banking\Policies;

use Modules\Finance\Banking\Models\BankingMigrationAccountIdentityExecution;
use Modules\Foundation\User\Models\User;

class BankingMigrationAccountIdentityExecutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.banking.migration.view');
    }

    public function view(User $user, BankingMigrationAccountIdentityExecution $execution): bool
    {
        return $user->can('finance.banking.migration.view');
    }

    public function execute(User $user): bool
    {
        return $user->can('finance.banking.migration.pilot.execution.execute');
    }
}

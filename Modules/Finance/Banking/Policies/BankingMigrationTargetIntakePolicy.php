<?php

namespace Modules\Finance\Banking\Policies;

use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Foundation\User\Models\User;

class BankingMigrationTargetIntakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.banking.migration.view');
    }

    public function view(User $user, BankingMigrationTargetIntake $targetIntake): bool
    {
        return $user->can('finance.banking.migration.view');
    }

    public function create(User $user): bool
    {
        return $user->can('finance.banking.migration.manage');
    }

    public function review(User $user, BankingMigrationTargetIntake $targetIntake): bool
    {
        return $user->can('finance.banking.migration.mapping.review');
    }
}

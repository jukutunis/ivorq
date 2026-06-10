<?php

namespace Modules\Finance\Banking\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Foundation\User\Models\User;

class BankStatementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('banking.statement.view');
    }

    public function view(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('banking.statement.view') && 
               $user->property_id === $statement->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('banking.statement.create');
    }

    public function import(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('banking.statement.import') && 
               $user->property_id === $statement->property_id;
    }
}

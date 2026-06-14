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
               app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $statement->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('banking.statement.create');
    }

    public function import(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('banking.statement.import') && 
               app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() === $statement->property_id;
    }
}

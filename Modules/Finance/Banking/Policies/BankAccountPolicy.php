<?php

namespace Modules\Finance\Banking\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Foundation\User\Models\User;

class BankAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('banking.bank-account.view');
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        if (app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() !== $bankAccount->property_id && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('banking.bank-account.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('banking.bank-account.create');
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        if (app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() !== $bankAccount->property_id && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('banking.bank-account.edit');
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        if (app(\Shared\Services\CurrentPropertyService::class)->getPropertyId() !== $bankAccount->property_id && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('banking.bank-account.delete');
    }
}

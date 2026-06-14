<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\Authorization\Models\User;
use Modules\Operations\Purchasing\Models\RFQ;
use Illuminate\Auth\Access\HandlesAuthorization;

class RFQPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.view');
    }

    public function view(User $user, RFQ $rfq): bool
    {
        if ($user->property_id !== $rfq->property_id) {
            return false;
        }
        return $user->can('purchasing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.create');
    }

    public function update(User $user, RFQ $rfq): bool
    {
        if ($user->property_id !== $rfq->property_id) {
            return false;
        }
        return $user->can('purchasing.update');
    }

    public function delete(User $user, RFQ $rfq): bool
    {
        if ($user->property_id !== $rfq->property_id) {
            return false;
        }
        return $user->can('purchasing.delete');
    }
}

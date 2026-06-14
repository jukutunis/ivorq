<?php

namespace Modules\Operations\Purchasing\Policies;

use Modules\Foundation\Authorization\Models\User;
use Modules\Operations\Purchasing\Models\Quotation;
use Illuminate\Auth\Access\HandlesAuthorization;

class QuotationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        // Quotation belongs to RFQ which belongs to property
        if ($user->property_id !== $quotation->rfq->property_id) {
            return false;
        }
        return $user->can('purchasing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        if ($user->property_id !== $quotation->rfq->property_id) {
            return false;
        }
        return $user->can('purchasing.update');
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        if ($user->property_id !== $quotation->rfq->property_id) {
            return false;
        }
        return $user->can('purchasing.delete');
    }
}

<?php

namespace Modules\Finance\AccountsPayable\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Foundation\User\Models\User;

class ApInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.view');
    }

    public function view(User $user, ApInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.view') && 
               $user->properties()->where('properties.id', $invoice->property_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.create');
    }

    public function update(User $user, ApInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.edit') && 
               $user->properties()->where('properties.id', $invoice->property_id)->exists();
    }

    public function delete(User $user, ApInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.delete') && 
               $user->properties()->where('properties.id', $invoice->property_id)->exists();
    }
}

<?php

namespace Modules\Finance\Payables\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Foundation\User\Models\User;

class VendorInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.view');
    }

    public function view(User $user, VendorInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.view') &&
               $user->property_id === $invoice->property_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.create');
    }

    public function update(User $user, VendorInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.edit') &&
               $user->property_id === $invoice->property_id &&
               $invoice->status === \Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum::Draft;
    }

    public function cancel(User $user, VendorInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.vendor-invoice.cancel') &&
               $user->property_id === $invoice->property_id &&
               in_array($invoice->status, [
                   \Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum::Draft,
                   \Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum::Submitted
               ]);
    }

    public function createMatch(User $user, VendorInvoice $invoice): bool
    {
        return $user->hasPermissionTo('payables.match.create') && 
               $user->property_id === $invoice->property_id;
    }
}

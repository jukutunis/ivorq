<?php

namespace Modules\Finance\Payables\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Finance\Payables\Models\PaymentVoucher;
use Modules\Foundation\User\Models\User;

class PaymentVoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payables.payment.view');
    }

    public function view(User $user, PaymentVoucher $paymentVoucher): bool
    {
        if ($user->property_id !== $paymentVoucher->property_id && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('payables.payment.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payables.payment.create');
    }

    public function post(User $user, PaymentVoucher $paymentVoucher): bool
    {
        if ($user->property_id !== $paymentVoucher->property_id && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('payables.payment.post');
    }

    public function cancel(User $user, PaymentVoucher $paymentVoucher): bool
    {
        if ($user->property_id !== $paymentVoucher->property_id && !$user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermissionTo('payables.payment.cancel');
    }
}

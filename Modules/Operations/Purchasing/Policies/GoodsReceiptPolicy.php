<?php

namespace Modules\Operations\Purchasing\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Foundation\User\Models\User;

class GoodsReceiptPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('goods-receipt.view-any');
    }

    public function view(User $user): bool
    {
        return $user->can('goods-receipt.view');
    }

    public function create(User $user): bool
    {
        return $user->can('goods-receipt.create');
    }
}

<?php

namespace Modules\Foundation\Approval\Policies;

use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\User\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApprovalPolicy
{
    use HandlesAuthorization;

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $user->property_id === $request->property_id;
    }

    public function action(User $user, ApprovalRequest $request): bool
    {
        // Must be in same property
        if ($user->property_id !== $request->property_id) {
            return false;
        }

        // Must be in pending/progress state
        if (!in_array($request->status, ['Pending', 'In Progress'])) {
            return false;
        }

        // Simplified for foundation testing: true if in same property
        return true;
    }
}

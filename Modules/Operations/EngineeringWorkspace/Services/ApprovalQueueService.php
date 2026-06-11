<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

use Modules\Foundation\User\Models\User;

class ApprovalQueueService
{
    public function getApprovalQueue(User $user): array
    {
        return [
            'pending_approvals' => [],
            'count' => 0,
        ];
    }
}

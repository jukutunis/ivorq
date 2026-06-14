<?php

namespace Modules\Foundation\Approval\Repositories;

use Modules\Foundation\Approval\Models\ApprovalRequest;

class ApprovalRequestRepository
{
    public function findPendingRequestFor(string $approvableType, string $approvableId): ?ApprovalRequest
    {
        return ApprovalRequest::where('approvable_type', $approvableType)
            ->where('approvable_id', $approvableId)
            ->whereIn('status', ['Pending', 'In Progress', 'Escalated'])
            ->first();
    }

    public function getRequestsPendingEscalation()
    {
        return ApprovalRequest::whereIn('status', ['Pending', 'In Progress'])
            ->get();
    }

    public function getRequestsPendingExpiration()
    {
        return ApprovalRequest::where('status', 'Escalated')
            ->get();
    }
}

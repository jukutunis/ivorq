<?php

namespace Modules\Foundation\Approval\Repositories;

use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Collection;

class ApprovalWorkflowRepository
{
    public function getActiveForApprovableType(string $type): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::with('steps.assignees')
            ->where('approvable_type', $type)
            ->where('is_active', true)
            ->first();
    }
}

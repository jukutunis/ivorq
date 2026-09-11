<?php

namespace Modules\Foundation\Task\Listeners;

use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Task\Services\TaskService;

class ApprovalTaskLifecycleListener
{
    public function __construct(private TaskService $taskService) {}

    public function handleApprovalCancelled(ApprovalCancelled $event): void
    {
        $this->taskService->cancelActiveApprovalTasks(
            $event->approvalRequest->id,
            $event->approvalRequest->property_id,
        );
    }
}

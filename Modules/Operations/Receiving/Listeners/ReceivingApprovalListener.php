<?php

namespace Modules\Operations\Receiving\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Services\ReceivingApprovalIntegrationService;
use Shared\Enums\PriorityEnum;

class ReceivingApprovalListener
{
    public function __construct(
        protected ReceivingApprovalIntegrationService $approvalIntegrationService,
        protected TaskService $taskService
    ) {}

    public function handleRequested(ApprovalRequested $event): void
    {
        $approvable = $event->approvalRequest->approvable;
        if ($approvable instanceof ReceivingDocument) {

            // Create task for the next steps
            $step = $event->approvalRequest->currentStep;
            if ($step) {
                foreach ($step->assignees as $assignee) {
                    try {
                        $task = $this->taskService->create([
                            'property_id' => $approvable->property_id,
                            'title' => "Review Receiving Document: {$approvable->grn_number}",
                            'description' => 'Please review the pending receiving document.',
                            'priority' => PriorityEnum::High->value,
                            'status' => TaskStatusEnum::Open->value,
                            'due_date' => now()->addDays(2),
                            'taskable_type' => get_class($approvable),
                            'taskable_id' => $approvable->id,
                            'approval_request_id' => $event->approvalRequest->id,
                            'source_module' => 'receiving',
                        ]);
                        $this->taskService->assignTask($task->id, 'user', $assignee->user_id);
                    } catch (\Throwable $e) {
                        Log::error('Failed to create receiving task: '.$e->getMessage());
                    }
                }
            }
        }
    }

    public function handleApproved(ApprovalApproved $event): void
    {
        $approvable = $event->approvalRequest->approvable;
        if ($approvable instanceof ReceivingDocument) {
            $approverAction = $event->approvalRequest->actions()->where('action_type', 'approve')->latest()->first();
            $approverId = $approverAction ? $approverAction->user_id : null;

            $this->approvalIntegrationService->handleApproval($approvable, $approverId);

            try {
                AppNotification::create([
                    'property_id' => $approvable->property_id,
                    'user_id' => $approvable->created_by,
                    'type' => 'receiving.approved',
                    'priority' => PriorityEnum::Normal->value,
                    'title' => 'Receiving Approved',
                    'body' => "Receiving Document {$approvable->grn_number} has been approved.",
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to create receiving notification: '.$e->getMessage());
            }
        }
    }

    public function handleRejected(ApprovalRejected $event): void
    {
        $approvable = $event->approvalRequest->approvable;
        if ($approvable instanceof ReceivingDocument) {
            $this->approvalIntegrationService->handleRejection($approvable, 'Approval workflow rejected.');
        }
    }

    public function handleCancelled(ApprovalCancelled $event): void
    {
        // Handle cancellation
    }
}

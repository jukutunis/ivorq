<?php

namespace Modules\Operations\Purchasing\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Shared\Enums\PriorityEnum;

class PurchasingApprovalListener
{
    public function __construct(
        protected TaskService $taskService
    ) {}

    public function handleApproved(ApprovalApproved $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            Log::info('Purchasing document approved: '.get_class($approvable).' ID: '.$approvable->getApprovableId());

            $title = ($approvable instanceof PurchaseRequest) ? "PR Approved: {$approvable->request_no}" : "PO Approved: {$approvable->po_no}";

            // Create Task
            $this->taskService->create([
                'property_id' => $approvable->getPropertyId(),
                'task_type' => 'Approval',
                'source_module' => 'purchasing',
                'taskable_type' => get_class($approvable),
                'taskable_id' => $approvable->id,
                'title' => $title,
                'description' => 'The document has been fully approved.',
                'priority' => PriorityEnum::High->value,
                'status' => TaskStatusEnum::Open->value,
                'due_date' => now()->addDays(1),
            ]);

            // Notification
            AppNotification::create([
                'property_id' => $approvable->getPropertyId(),
                'user_id' => $approvable->requester_id ?? $approvable->created_by ?? null, // Notify requester
                'type' => 'purchasing.approved',
                'priority' => 'high',
                'title' => $title,
                'body' => 'Your purchasing document has been approved.',
            ]);
        }
    }

    public function handleRequested(ApprovalRequested $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            $title = ($approvable instanceof PurchaseRequest) ? "PR Approval Required: {$approvable->request_no}" : "PO Approval Required: {$approvable->po_no}";

            $this->taskService->create([
                'property_id' => $approvable->getPropertyId(),
                'task_type' => 'Approval',
                'source_module' => 'purchasing',
                'taskable_type' => get_class($approvable),
                'taskable_id' => $approvable->id,
                'approval_request_id' => $event->approvalRequest->id,
                'title' => $title,
                'description' => 'Please review and approve this document.',
                'priority' => PriorityEnum::High->value,
                'status' => TaskStatusEnum::Open->value,
                'due_date' => now()->addDays(2),
            ]);

            // In real app, we'd find the current step's assignees and notify them
            // Mocking a general notification here
            AppNotification::create([
                'property_id' => $approvable->getPropertyId(),
                'user_id' => $approvable->requester_id ?? $approvable->created_by ?? null,
                'type' => 'purchasing.approval_required',
                'priority' => 'high',
                'title' => $title,
                'body' => 'A document requires your approval.',
            ]);
        }
    }

    public function handleRejected(ApprovalRejected $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            Log::info('Purchasing document rejected: '.get_class($approvable).' ID: '.$approvable->getApprovableId());
        }
    }

    public function handleCancelled(ApprovalCancelled $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            Log::info('Purchasing approval request cancelled: '.get_class($approvable).' ID: '.$approvable->getApprovableId());
        }
    }

    private function isPurchasingDocument(mixed $approvable): bool
    {
        return $approvable instanceof PurchaseRequest || $approvable instanceof PurchaseOrder;
    }
}

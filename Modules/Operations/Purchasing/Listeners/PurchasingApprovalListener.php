<?php

namespace Modules\Operations\Purchasing\Listeners;

use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Illuminate\Support\Facades\Log;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Foundation\Notification\Models\AppNotification;

class PurchasingApprovalListener
{
    public function __construct(
        protected TaskService $taskService
    ) {}
    public function handleApproved(ApprovalApproved $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            $approvable->markAsApproved();
            Log::info("Purchasing document approved: " . get_class($approvable) . " ID: " . $approvable->getApprovableId());

            $title = ($approvable instanceof PurchaseRequest) ? "PR Approved: {$approvable->request_no}" : "PO Approved: {$approvable->po_no}";
            
            // Create Task
            $this->taskService->create([
                'property_id' => $approvable->getPropertyId(),
                'task_type' => 'Approval',
                'source_module' => 'purchasing',
                'taskable_type' => get_class($approvable),
                'taskable_id' => $approvable->id,
                'title' => $title,
                'description' => "The document has been fully approved.",
                'priority' => \Shared\Enums\PriorityEnum::High->value,
                'status' => \Modules\Foundation\Task\Enums\TaskStatusEnum::Open->value,
                'due_date' => now()->addDays(1),
            ]);

            // Notification
            AppNotification::create([
                'property_id' => $approvable->getPropertyId(),
                'user_id' => $approvable->requester_id ?? $approvable->created_by ?? null, // Notify requester
                'type' => 'purchasing.approved',
                'priority' => 'high',
                'title' => $title,
                'body' => "Your purchasing document has been approved.",
            ]);
        }
    }

    public function handleRequested(\Modules\Foundation\Approval\Events\ApprovalRequested $event): void
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
                'title' => $title,
                'description' => "Please review and approve this document.",
                'priority' => \Shared\Enums\PriorityEnum::High->value,
                'status' => \Modules\Foundation\Task\Enums\TaskStatusEnum::Open->value,
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
                'body' => "A document requires your approval.",
            ]);
        }
    }

    public function handleRejected(ApprovalRejected $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            $approvable->markAsRejected();
            Log::info("Purchasing document rejected: " . get_class($approvable) . " ID: " . $approvable->getApprovableId());
        }
    }

    public function handleCancelled(ApprovalCancelled $event): void
    {
        $approvable = $event->approvalRequest->approvable;

        if ($this->isPurchasingDocument($approvable)) {
            $approvable->markAsRejected('Approval Cancelled');
            Log::info("Purchasing document cancelled: " . get_class($approvable) . " ID: " . $approvable->getApprovableId());
        }
    }

    private function isPurchasingDocument(ApprovableContract $approvable): bool
    {
        return $approvable instanceof PurchaseRequest || $approvable instanceof PurchaseOrder;
    }
}

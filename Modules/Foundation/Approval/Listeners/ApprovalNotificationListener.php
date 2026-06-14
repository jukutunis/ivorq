<?php

namespace Modules\Foundation\Approval\Listeners;

use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Notification\Models\NotificationPreference;
use Modules\Foundation\Approval\Events\ApprovalDelegated;
use Modules\Foundation\Approval\Events\ApprovalEscalated;
use Modules\Foundation\Approval\Events\ApprovalExpired;
use Illuminate\Contracts\Queue\ShouldQueue;

class ApprovalNotificationListener implements ShouldQueue
{
    public function handleApprovalRequested(ApprovalRequested $event): void
    {
        // Get step assignees from the snapshot and current step id
        $request = $event->approvalRequest;
        $stepSnapshot = collect($request->step_snapshot)->firstWhere('id', $request->current_step_id);
        
        if ($stepSnapshot && isset($stepSnapshot['assignees'])) {
            // Simplified foundation demo: assume users are explicitly in assignees or resolved
            foreach ($stepSnapshot['assignees'] as $assignee) {
                if ($assignee['assignee_type'] === 'USER' && !empty($assignee['user_id'])) {
                    if ($this->canSendNotification($assignee['user_id'], $request->property_id)) {
                        AppNotification::create([
                            'property_id' => $request->property_id,
                            'user_id' => $assignee['user_id'],
                            'type' => 'ApprovalRequest',
                            'title' => 'Approval Required: ' . $request->approvable_type,
                            'body' => 'You have a pending approval request.',
                            'priority' => 'high',
                            'data' => [
                                'approval_request_id' => $request->id,
                            ]
                        ]);
                    }
                }
            }
        }
    }

    public function handleApprovalApproved(ApprovalApproved $event): void
    {
        $request = $event->approvalRequest;
        if ($this->canSendNotification($request->requester_id, $request->property_id)) {
            AppNotification::create([
                'property_id' => $request->property_id,
                'user_id' => $request->requester_id,
                'type' => 'ApprovalApproved',
                'title' => 'Request Approved',
                'body' => 'Your request has been fully approved.',
                'priority' => 'normal',
            ]);
        }
    }

    public function handleApprovalRejected(ApprovalRejected $event): void
    {
        $request = $event->approvalRequest;
        if ($this->canSendNotification($request->requester_id, $request->property_id)) {
            AppNotification::create([
                'property_id' => $request->property_id,
                'user_id' => $request->requester_id,
                'type' => 'ApprovalRejected',
                'title' => 'Request Rejected',
                'body' => 'Your request was rejected.',
                'priority' => 'high',
            ]);
        }
    }

    public function handleApprovalDelegated(ApprovalDelegated $event): void
    {
        $request = $event->approvalRequest;

        if ($this->canSendNotification($event->delegatedFrom->id, $request->property_id)) {
            AppNotification::create([
                'property_id' => $request->property_id,
                'user_id' => $event->delegatedFrom->id,
                'type' => 'ApprovalDelegated',
                'title' => 'Approval Delegated',
                'body' => 'Your approval request was delegated to ' . $event->delegatedTo->name,
                'priority' => 'normal',
            ]);
        }

        if ($this->canSendNotification($event->delegatedTo->id, $request->property_id)) {
            AppNotification::create([
                'property_id' => $request->property_id,
                'user_id' => $event->delegatedTo->id,
                'type' => 'ApprovalDelegated',
                'title' => 'Approval Delegated To You',
                'body' => 'You have been delegated an approval request.',
                'priority' => 'high',
                'data' => ['approval_request_id' => $request->id]
            ]);
        }
    }

    public function handleApprovalEscalated(ApprovalEscalated $event): void
    {
        $request = $event->approvalRequest;
        $step = $event->approvalStep;
        
        // Notify requester
        if ($this->canSendNotification($request->requester_id, $request->property_id)) {
            AppNotification::create([
                'property_id' => $request->property_id,
                'user_id' => $request->requester_id,
                'type' => 'ApprovalEscalated',
                'title' => 'Approval Escalated',
                'body' => 'Your approval request has been escalated due to SLA timeout.',
                'priority' => 'high',
                'data' => ['approval_request_id' => $request->id]
            ]);
        }
    }

    public function handleApprovalExpired(ApprovalExpired $event): void
    {
        $request = $event->approvalRequest;
        
        if ($this->canSendNotification($request->requester_id, $request->property_id)) {
            AppNotification::create([
                'property_id' => $request->property_id,
                'user_id' => $request->requester_id,
                'type' => 'ApprovalExpired',
                'title' => 'Approval Expired',
                'body' => 'Your approval request has expired and was cancelled.',
                'priority' => 'high',
            ]);
        }
    }

    private function canSendNotification(string $userId, string $propertyId): bool
    {
        $pref = NotificationPreference::where('user_id', $userId)
            ->where('property_id', $propertyId)
            ->where('notification_type', 'approvals')
            ->first();

        if ($pref && $pref->is_muted) {
            return false;
        }

        if ($pref && !$pref->in_app_enabled) {
            return false;
        }

        return true;
    }
}

<?php

namespace Modules\Operations\Engineering\Listeners;

use Modules\Foundation\Activity\Services\ActivityService;
use Modules\Operations\Engineering\Events\AssetRequestApproved;
use Modules\Operations\Engineering\Events\AssetRequestFulfilled;
use Modules\Operations\Engineering\Events\AssetRequestRejected;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskCompleted;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskGenerated;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskOverdue;
use Modules\Operations\Engineering\Events\WorkOrderAssigned;
use Modules\Operations\Engineering\Events\WorkOrderCancelled;
use Modules\Operations\Engineering\Events\WorkOrderCompleted;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Events\WorkOrderOnHold;
use Modules\Operations\Engineering\Events\WorkOrderStarted;

class LogEngineeringActivity
{
    public function __construct(
        private ActivityService $activityService
    ) {}

    public function handle(
        WorkOrderCreated|WorkOrderAssigned|WorkOrderStarted|WorkOrderOnHold|WorkOrderCompleted|WorkOrderCancelled|PreventiveMaintenanceTaskGenerated|PreventiveMaintenanceTaskCompleted|PreventiveMaintenanceTaskOverdue|AssetRequestApproved|AssetRequestRejected|AssetRequestFulfilled $event
    ): void {
        match (true) {
            $event instanceof WorkOrderCreated                  => $this->onWorkOrderCreated($event),
            $event instanceof WorkOrderAssigned                 => $this->onWorkOrderAssigned($event),
            $event instanceof WorkOrderStarted                  => $this->onWorkOrderStarted($event),
            $event instanceof WorkOrderOnHold                   => $this->onWorkOrderOnHold($event),
            $event instanceof WorkOrderCompleted                => $this->onWorkOrderCompleted($event),
            $event instanceof WorkOrderCancelled                => $this->onWorkOrderCancelled($event),
            $event instanceof PreventiveMaintenanceTaskGenerated => $this->onPmTaskGenerated($event),
            $event instanceof PreventiveMaintenanceTaskCompleted => $this->onPmTaskCompleted($event),
            $event instanceof PreventiveMaintenanceTaskOverdue   => $this->onPmTaskOverdue($event),
            $event instanceof AssetRequestApproved              => $this->onAssetRequestApproved($event),
            $event instanceof AssetRequestRejected              => $this->onAssetRequestRejected($event),
            $event instanceof AssetRequestFulfilled             => $this->onAssetRequestFulfilled($event),
        };
    }

    private function onWorkOrderCreated(WorkOrderCreated $event): void
    {
        $wo = $event->workOrder;

        $this->activityService->log(
            description: "Work order [{$wo->work_order_number}] {$wo->title} was created",
            subject: $wo,
        );
    }

    private function onWorkOrderAssigned(WorkOrderAssigned $event): void
    {
        $wo         = $event->workOrder;
        $techName   = $event->assignment->user?->name ?? $event->assignment->user_id;

        $this->activityService->log(
            description: "Work order [{$wo->work_order_number}] was assigned to {$techName}",
            subject: $wo,
        );
    }

    private function onWorkOrderStarted(WorkOrderStarted $event): void
    {
        $wo = $event->workOrder;

        $this->activityService->log(
            description: "Work order [{$wo->work_order_number}] {$wo->title} was started",
            subject: $wo,
        );
    }

    private function onWorkOrderOnHold(WorkOrderOnHold $event): void
    {
        $wo     = $event->workOrder;
        $reason = $event->reason ? " — {$event->reason}" : '';

        $this->activityService->log(
            description: "Work order [{$wo->work_order_number}] {$wo->title} was placed on hold{$reason}",
            subject: $wo,
        );
    }

    private function onWorkOrderCompleted(WorkOrderCompleted $event): void
    {
        $wo = $event->workOrder;
        $by = $wo->completedBy?->name ?? $wo->completed_by ?? 'unknown';

        $this->activityService->log(
            description: "Work order [{$wo->work_order_number}] {$wo->title} was completed by {$by}",
            subject: $wo,
        );
    }

    private function onWorkOrderCancelled(WorkOrderCancelled $event): void
    {
        $wo     = $event->workOrder;
        $reason = $event->reason ? " — {$event->reason}" : '';

        $this->activityService->log(
            description: "Work order [{$wo->work_order_number}] {$wo->title} was cancelled{$reason}",
            subject: $wo,
        );
    }

    private function onPmTaskGenerated(PreventiveMaintenanceTaskGenerated $event): void
    {
        $task = $event->task;
        $pmTitle = $task->preventiveMaintenance?->title ?? $task->preventive_maintenance_id;

        $this->activityService->log(
            description: "PM task generated for [{$pmTitle}] scheduled on {$task->scheduled_date?->toDateString()}",
            subject: $task,
        );
    }

    private function onPmTaskCompleted(PreventiveMaintenanceTaskCompleted $event): void
    {
        $task    = $event->task;
        $pmTitle = $task->preventiveMaintenance?->title ?? $task->preventive_maintenance_id;
        $by      = $task->completedBy?->name ?? $task->completed_by ?? 'unknown';

        $this->activityService->log(
            description: "PM task for [{$pmTitle}] was completed by {$by}",
            subject: $task,
        );
    }

    private function onPmTaskOverdue(PreventiveMaintenanceTaskOverdue $event): void
    {
        $task    = $event->task;
        $pmTitle = $task->preventiveMaintenance?->title ?? $task->preventive_maintenance_id;

        $this->activityService->log(
            description: "PM task for [{$pmTitle}] scheduled on {$task->scheduled_date?->toDateString()} is now overdue",
            subject: $task,
        );
    }

    private function onAssetRequestApproved(AssetRequestApproved $event): void
    {
        $req = $event->request;
        $by  = $req->approvedBy?->name ?? $req->approved_by ?? 'unknown';

        $this->activityService->log(
            description: "Asset request [{$req->request_number}] {$req->title} was approved by {$by}",
            subject: $req,
        );
    }

    private function onAssetRequestRejected(AssetRequestRejected $event): void
    {
        $req    = $event->request;
        $reason = $event->reason ? " — {$event->reason}" : '';

        $this->activityService->log(
            description: "Asset request [{$req->request_number}] {$req->title} was rejected{$reason}",
            subject: $req,
        );
    }

    private function onAssetRequestFulfilled(AssetRequestFulfilled $event): void
    {
        $req = $event->request;
        $by  = $req->fulfilledBy?->name ?? $req->fulfilled_by ?? 'unknown';

        $this->activityService->log(
            description: "Asset request [{$req->request_number}] {$req->title} was fulfilled by {$by}",
            subject: $req,
        );
    }
}

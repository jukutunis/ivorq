<?php

namespace Modules\Operations\Housekeeping\Listeners;

use Modules\Foundation\Activity\Services\ActivityService;
use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskCancelled;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Events\CleaningTaskCreated;
use Modules\Operations\Housekeeping\Events\CleaningTaskStarted;
use Modules\Operations\Housekeeping\Events\InspectionCompleted;
use Modules\Operations\Housekeeping\Events\RoomCreated;
use Modules\Operations\Housekeeping\Events\RoomStatusChanged;

class LogHousekeepingActivity
{
    public function __construct(
        private ActivityService $activityService
    ) {}

    public function handle(
        RoomCreated|RoomStatusChanged|CleaningTaskCreated|CleaningTaskAssigned|CleaningTaskStarted|CleaningTaskCompleted|CleaningTaskCancelled|InspectionCompleted $event
    ): void {
        match (true) {
            $event instanceof RoomCreated          => $this->onRoomCreated($event),
            $event instanceof RoomStatusChanged    => $this->onRoomStatusChanged($event),
            $event instanceof CleaningTaskCreated  => $this->onTaskCreated($event),
            $event instanceof CleaningTaskAssigned => $this->onTaskAssigned($event),
            $event instanceof CleaningTaskStarted  => $this->onTaskStarted($event),
            $event instanceof CleaningTaskCompleted => $this->onTaskCompleted($event),
            $event instanceof CleaningTaskCancelled => $this->onTaskCancelled($event),
            $event instanceof InspectionCompleted   => $this->onInspectionCompleted($event),
        };
    }

    private function onRoomCreated(RoomCreated $event): void
    {
        $this->activityService->log(
            description: "Room [{$event->room->room_number}] {$event->room->room_name} was created",
            subject: $event->room,
        );
    }

    private function onRoomStatusChanged(RoomStatusChanged $event): void
    {
        $from  = $event->from  ?? 'unset';
        $to    = $event->to    ?? 'unset';
        $field = ucfirst($event->statusField);

        $this->activityService->log(
            description: "Room [{$event->room->room_number}] {$field} changed from {$from} to {$to}",
            subject: $event->room,
        );
    }

    private function onTaskCreated(CleaningTaskCreated $event): void
    {
        $roomRef = $event->task->room_id ? " for room [{$event->task->room_id}]" : '';

        $this->activityService->log(
            description: "Cleaning task [{$event->task->task_code}] {$event->task->title} was created{$roomRef}",
            subject: $event->task,
        );
    }

    private function onTaskAssigned(CleaningTaskAssigned $event): void
    {
        $userName = $event->assignment->user?->name ?? $event->assignment->user_id;

        $this->activityService->log(
            description: "Cleaning task [{$event->task->task_code}] was assigned to {$userName}",
            subject: $event->task,
        );
    }

    private function onTaskStarted(CleaningTaskStarted $event): void
    {
        $this->activityService->log(
            description: "Cleaning task [{$event->task->task_code}] {$event->task->title} was started",
            subject: $event->task,
        );
    }

    private function onTaskCompleted(CleaningTaskCompleted $event): void
    {
        $by = $event->task->completedBy?->name ?? $event->task->completed_by ?? 'unknown';

        $this->activityService->log(
            description: "Cleaning task [{$event->task->task_code}] {$event->task->title} was completed by {$by}",
            subject: $event->task,
        );
    }

    private function onTaskCancelled(CleaningTaskCancelled $event): void
    {
        $reason = $event->reason ? " — {$event->reason}" : '';

        $this->activityService->log(
            description: "Cleaning task [{$event->task->task_code}] {$event->task->title} was cancelled{$reason}",
            subject: $event->task,
        );
    }

    private function onInspectionCompleted(InspectionCompleted $event): void
    {
        $outcome  = ucfirst($event->inspection->status->value);
        $roomRef  = $event->inspection->room_id;
        $severity = $event->inspection->inspection_severity
            ? " (severity: {$event->inspection->inspection_severity->value})"
            : '';

        $this->activityService->log(
            description: "Inspection for room [{$roomRef}] {$outcome}{$severity}",
            subject: $event->inspection,
        );
    }
}

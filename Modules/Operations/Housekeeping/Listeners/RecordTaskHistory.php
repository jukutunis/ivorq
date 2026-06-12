<?php

namespace Modules\Operations\Housekeeping\Listeners;

use Modules\Foundation\Activity\Services\ActivityService;
use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskCancelled;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Events\CleaningTaskCreated;
use Modules\Operations\Housekeeping\Events\CleaningTaskStarted;

/**
 * Records task lifecycle events as structured history entries.
 *
 * A dedicated cleaning_task_histories table is not included in Sprint 03.
 * This listener writes structured machine-readable records to activity_logs
 * via ActivityService::log() properties until a dedicated history table is added.
 *
 * When cleaning_task_histories is added in a future sprint, replace the
 * activityService calls with CleaningTaskHistory::record() and remove this comment.
 */
class RecordTaskHistory
{
    public function __construct(
        private ActivityService $activityService
    ) {}

    public function handle(
        CleaningTaskCreated|CleaningTaskAssigned|CleaningTaskStarted|CleaningTaskCompleted|CleaningTaskCancelled $event
    ): void {
        match (true) {
            $event instanceof CleaningTaskCreated   => $this->onTaskCreated($event),
            $event instanceof CleaningTaskAssigned  => $this->onTaskAssigned($event),
            $event instanceof CleaningTaskStarted   => $this->onTaskStarted($event),
            $event instanceof CleaningTaskCompleted => $this->onTaskCompleted($event),
            $event instanceof CleaningTaskCancelled => $this->onTaskCancelled($event),
        };
    }

    private function onTaskCreated(CleaningTaskCreated $event): void
    {
        $this->activityService->log(
            description: "task_created",
            subject: $event->task,
            properties: [
                'action'    => 'task_created',
                'task_code' => $event->task->task_code,
                'task_type' => $event->task->task_type instanceof \UnitEnum ? $event->task->task_type->value : $event->task->task_type,
                'status'    => $event->task->status instanceof \UnitEnum ? $event->task->status->value : $event->task->status,
                'room_id'   => $event->task->room_id,
                'zone_id'   => $event->task->zone_id,
            ],
        );
    }

    private function onTaskAssigned(CleaningTaskAssigned $event): void
    {
        $this->activityService->log(
            description: "task_assigned",
            subject: $event->task,
            properties: [
                'action'        => 'task_assigned',
                'task_code'     => $event->task->task_code,
                'status'        => $event->task->status instanceof \UnitEnum ? $event->task->status->value : $event->task->status,
                'assigned_to'   => $event->assignment->user_id,
                'department_id' => $event->assignment->department_id,
            ],
        );
    }

    private function onTaskStarted(CleaningTaskStarted $event): void
    {
        $this->activityService->log(
            description: "task_started",
            subject: $event->task,
            properties: [
                'action'     => 'task_started',
                'task_code'  => $event->task->task_code,
                'status'     => $event->task->status instanceof \UnitEnum ? $event->task->status->value : $event->task->status,
                'started_at' => $event->task->started_at?->toIso8601String(),
            ],
        );
    }

    private function onTaskCompleted(CleaningTaskCompleted $event): void
    {
        $this->activityService->log(
            description: "task_completed",
            subject: $event->task,
            properties: [
                'action'        => 'task_completed',
                'task_code'     => $event->task->task_code,
                'status'        => $event->task->status instanceof \UnitEnum ? $event->task->status->value : $event->task->status,
                'completed_by'  => $event->task->completed_by,
                'completed_at'  => $event->task->completed_at?->toIso8601String(),
            ],
        );
    }

    private function onTaskCancelled(CleaningTaskCancelled $event): void
    {
        $this->activityService->log(
            description: "task_cancelled",
            subject: $event->task,
            properties: [
                'action'    => 'task_cancelled',
                'task_code' => $event->task->task_code,
                'status'    => $event->task->status instanceof \UnitEnum ? $event->task->status->value : $event->task->status,
                'reason'    => $event->reason,
            ],
        );
    }
}

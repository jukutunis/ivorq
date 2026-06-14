<?php

namespace Modules\Foundation\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Notification\Models\NotificationPreference;
use Modules\Foundation\Task\Events\TaskAssigned;
use Modules\Foundation\Task\Events\TaskCancelled;
use Modules\Foundation\Task\Events\TaskCompleted;
use Modules\Foundation\Task\Events\TaskOverdue;
use Modules\Foundation\Task\Events\TaskReassigned;

class TaskEventListener implements ShouldQueue
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(TaskAssigned::class, [self::class, 'handleTaskAssigned']);
        $events->listen(TaskReassigned::class, [self::class, 'handleTaskReassigned']);
        $events->listen(TaskCompleted::class, [self::class, 'handleTaskCompleted']);
        $events->listen(TaskCancelled::class, [self::class, 'handleTaskCancelled']);
        $events->listen(TaskOverdue::class, [self::class, 'handleTaskOverdue']);
    }

    public function handleTaskAssigned(TaskAssigned $event): void
    {
        $this->notifyUsers('TaskAssigned', $event->task, $event->assignment->assignee_id, "New task assigned: {$event->task->title}");
    }

    public function handleTaskReassigned(TaskReassigned $event): void
    {
        $this->notifyUsers('TaskReassigned', $event->task, $event->newAssignment->assignee_id, "Task reassigned to you: {$event->task->title}");
    }

    public function handleTaskCompleted(TaskCompleted $event): void
    {
        $this->notifyUsers('TaskCompleted', $event->task, $event->task->created_by, "Task completed: {$event->task->title}");
    }

    public function handleTaskCancelled(TaskCancelled $event): void
    {
        // For cancellations, we might want to notify all assignees and watchers
        // For simplicity, we'll notify the created_by user
        if ($event->task->created_by) {
            $this->notifyUsers('TaskCancelled', $event->task, $event->task->created_by, "Task cancelled: {$event->task->title}");
        }
    }

    public function handleTaskOverdue(TaskOverdue $event): void
    {
        // Notify assignees
        foreach ($event->task->assignments as $assignment) {
            $this->notifyUsers('TaskOverdue', $event->task, $assignment->assignee_id, "Task overdue: {$event->task->title}");
        }
    }

    private function notifyUsers(string $type, $task, ?string $userId, string $title): void
    {
        if (!$userId) {
            return;
        }

        // Check preferences
        $pref = NotificationPreference::where('property_id', $task->property_id)
            ->where('user_id', $userId)
            ->where('notification_type', $type)
            ->first();

        if ($pref && $pref->is_muted) {
            return;
        }

        // We only implement In-App DB Notifications for now
        $inAppEnabled = $pref ? $pref->in_app_enabled : true;

        if ($inAppEnabled) {
            AppNotification::create([
                'property_id' => $task->property_id,
                'user_id' => $userId,
                'type' => $type,
                'priority' => $task->priority,
                'title' => $title,
                'body' => $task->description ?? 'No description provided.',
                'data' => [
                    'task_id' => $task->id,
                    'taskable_type' => $task->taskable_type,
                    'taskable_id' => $task->taskable_id,
                ],
            ]);
        }
    }
}

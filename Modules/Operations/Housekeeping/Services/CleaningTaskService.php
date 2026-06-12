<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Models\Room;

class CleaningTaskService
{
    public function generateDepartureTask(Room $room): CleaningTask
    {
        $task = CleaningTask::create([
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'task_type' => 'departure',
            'status' => 'pending',
            'priority' => $room->is_vip ? 'rush' : 'normal',
            'credits' => 1.0,
            'sla_minutes_target' => 45,
        ]);
        return $task;
    }
    
    public function generateTurndownTask(Room $room): CleaningTask
    {
        $task = CleaningTask::create([
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'task_type' => 'turndown',
            'status' => 'pending',
            'priority' => 'normal',
            'credits' => 0.5,
            'sla_minutes_target' => 15,
        ]);
        return $task;
    }

    // Support Legacy tests
    public function create(array $data): CleaningTask
    {
        if (!isset($data['status'])) {
            $data['status'] = 'pending';
        }
        $task = CleaningTask::create($data);
        return $task;
    }

    public function assign(string $taskId, array $data): TaskAssignment
    {
        $task = CleaningTask::findOrFail($taskId);
        $task->update(['status' => 'assigned']);

        $assignment = TaskAssignment::create([
            'cleaning_task_id' => $taskId,
            'user_id' => $data['user_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'assigned_at' => now(),
            'status' => 'active',
        ]);
        
        event(new \Modules\Operations\Housekeeping\Events\CleaningTaskAssigned($task, $assignment));
        return $assignment;
    }

    public function changeStatus(string $taskId, $status, $userId = null): CleaningTask
    {
        $task = CleaningTask::findOrFail($taskId);
        
        $targetEnum = $status instanceof \UnitEnum ? $status : \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::tryFrom($status);
        $currentEnum = $task->status instanceof \UnitEnum ? $task->status : \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::tryFrom($task->status);
        
        if ($targetEnum && $currentEnum && !$currentEnum->canTransitionTo($targetEnum)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'Invalid status transition'
            ]);
        }
        
        $statusString = $targetEnum ? $targetEnum->value : $status;
        
        $updates = ['status' => $statusString];
        if ($statusString === 'in_progress') {
            $updates['started_at'] = now();
        } elseif ($statusString === 'completed') {
            $updates['completed_at'] = now();
            $completedBy = $userId ?? auth()->id();
            if ($completedBy) {
                $updates['completed_by'] = $completedBy;
            }
        }
        $task->update($updates);
        
        if ($statusString === 'in_progress') {
            event(new \Modules\Operations\Housekeeping\Events\CleaningTaskStarted($task));
        } elseif ($statusString === 'completed') {
            event(new \Modules\Operations\Housekeeping\Events\CleaningTaskCompleted($task));
        } elseif ($statusString === 'cancelled') {
            event(new \Modules\Operations\Housekeeping\Events\CleaningTaskCancelled($task, null));
        }
        
        return $task;
    }

    public function find(string $taskId): CleaningTask
    {
        return CleaningTask::findOrFail($taskId);
    }
}
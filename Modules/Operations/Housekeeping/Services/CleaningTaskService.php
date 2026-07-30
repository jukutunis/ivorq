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
            'task_type' => 'checkout_cleaning',
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
        $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        if (isset($data['property_id']) && $data['property_id'] !== $currentPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        if (!isset($data['status'])) {
            $data['status'] = 'pending';
        }
        $task = CleaningTask::create($data);
        return $task;
    }

    public function find(string $taskId): CleaningTask
    {
        $task = CleaningTask::findOrFail($taskId);
        $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();

        if ($currentPropertyId !== null && $task->property_id !== $currentPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        return $task;
    }



    public function assign(string $taskId, array $data): TaskAssignment
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($taskId, $data) {
            $task = CleaningTask::findOrFail($taskId);

            $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            if ($task->property_id !== $currentPropertyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
            }

            if ($data['user_id'] ?? null) {
                $attendant = \Modules\Foundation\User\Models\User::findOrFail($data['user_id']);
                if (!$attendant->properties()->where('properties.id', $task->property_id)->exists()) {
                    throw new \Exception("Attendant must belong to the same property context.");
                }
            }

            $task->update(['status' => 'assigned']);

            TaskAssignment::where('cleaning_task_id', $task->id)
                ->where('status', 'active')
                ->update(['status' => 'reassigned']);

            $assignment = TaskAssignment::create([
                'cleaning_task_id' => $taskId,
                'user_id' => $data['user_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'assigned_at' => now(),
                'status' => 'active',
            ]);

            event(new \Modules\Operations\Housekeeping\Events\CleaningTaskAssigned($task, $assignment));
            return $assignment;
        });
    }

    public function changeStatus(string $taskId, $status, $userId = null, ?string $notes = null): CleaningTask
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($taskId, $status, $userId, $notes) {
            $task = CleaningTask::findOrFail($taskId);

            $currentPropertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            if ($task->property_id !== $currentPropertyId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
            }

            $targetEnum = $status instanceof \UnitEnum ? $status : \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::tryFrom($status);
            $currentEnum = $task->status instanceof \UnitEnum ? $task->status : \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::tryFrom($task->status);

            if ($targetEnum && $currentEnum && !$currentEnum->canTransitionTo($targetEnum)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'Invalid status transition'
                ]);
            }

            $statusString = $targetEnum ? $targetEnum->value : $status;

            if ($statusString === 'in_progress' || $statusString === 'completed') {
                $userId ??= auth()->id();
                if (!$userId) {
                    throw new \Exception("User ID is required to update status to {$statusString}.");
                }
                $hasActiveAssignment = TaskAssignment::where('cleaning_task_id', $task->id)
                    ->where('status', 'active')
                    ->exists();
                $hasAssignment = TaskAssignment::where('cleaning_task_id', $task->id)
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->exists();
                if ($hasActiveAssignment && !$hasAssignment) {
                    throw new \Exception("Only the active assigned room attendant can start or complete this task.");
                }
            }

            if ($statusString === 'completed') {
                if (empty($notes) || trim($notes) === '') {
                    $notes = 'Completed';
                }
            }

            $updates = ['status' => $statusString];
            if ($statusString === 'in_progress') {
                $updates['started_at'] = now();
            } elseif ($statusString === 'completed') {
                $updates['completed_at'] = now();
                $updates['completed_by'] = $userId;
                $updates['notes'] = $notes;
            }
            $task->update($updates);

            if ($statusString === 'in_progress') {
                event(new \Modules\Operations\Housekeeping\Events\CleaningTaskStarted($task));
            } elseif ($statusString === 'completed') {
                if ($task->room_id !== null) {
                    // Transition room cleanliness status to clean (Awaiting Inspection)
                    $roomService = app(\Modules\Operations\Housekeeping\Services\RoomService::class);
                    $room = $roomService->changeCleanlinessStatus(
                        $task->room_id,
                        \Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum::Clean,
                        'Cleaning completed by attendant'
                    );

                    $room->readiness_state = 'waiting_inspection';
                    $room->save();

                    \Modules\Operations\Housekeeping\Models\RoomInspection::create([
                        'property_id' => $task->property_id,
                        'room_id' => $task->room_id,
                        'cleaning_task_id' => $task->id,
                        'status' => 'pending',
                        'inspection_type' => 'post_cleaning',
                    ]);
                }

                event(new \Modules\Operations\Housekeeping\Events\CleaningTaskCompleted($task));
            } elseif ($statusString === 'cancelled') {
                event(new \Modules\Operations\Housekeeping\Events\CleaningTaskCancelled($task, null));
            }

            return $task;
        });
    }

}

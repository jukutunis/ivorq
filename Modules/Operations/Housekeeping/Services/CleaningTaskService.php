<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Foundation\User\Models\User;

class CleaningTaskService
{
    public function __construct(
        private readonly HousekeepingCleaningInspectionReadinessLifecycleService $lifecycle,
    ) {}

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
        $target = $status instanceof \Modules\Operations\Housekeeping\Enums\TaskStatusEnum
            ? $status
            : \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::tryFrom((string) $status);
        if (! $target) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'Invalid status transition',
            ]);
        }

        $task = CleaningTask::withoutGlobalScopes()->find($taskId);
        if (! $task || ($task->status !== $target && ! $task->status->canTransitionTo($target))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'Invalid status transition',
            ]);
        }

        $actor = $userId ? User::withoutGlobalScopes()->find($userId) : auth()->user();
        if (! $actor instanceof User) {
            throw new \DomainException('An authenticated Housekeeping actor is required.');
        }

        return $this->lifecycle->changeCleaningTaskStatus($actor, $taskId, $target, $notes);
    }

}

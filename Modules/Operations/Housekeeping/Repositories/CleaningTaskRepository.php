<?php

namespace Modules\Operations\Housekeeping\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Shared\Exceptions\NotFoundException;

class CleaningTaskRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = CleaningTask::with(['room', 'zone'])->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['task_type'])) {
            $query->where('task_type', $filters['task_type']);
        }

        if (! empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (! empty($filters['zone_id'])) {
            $query->where('zone_id', $filters['zone_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): CleaningTask
    {
        $task = CleaningTask::with(['room', 'zone', 'assignments.user', 'assignments.department'])->find($id);

        throw_if(! $task, new NotFoundException('CleaningTask'));

        return $task;
    }

    public function findOrFail(string $id): CleaningTask
    {
        return CleaningTask::findOrFail($id);
    }

    public function create(array $data): CleaningTask
    {
        return CleaningTask::create($data)->fresh();
    }

    public function update(string $id, array $data): CleaningTask
    {
        $task = $this->find($id);
        $task->update($data);

        return $task->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function byRoom(string $roomId): Collection
    {
        return CleaningTask::where('room_id', $roomId)
            ->with(['zone', 'assignments.user'])
            ->latest()
            ->get();
    }

    public function byZone(string $zoneId): Collection
    {
        return CleaningTask::where('zone_id', $zoneId)
            ->with(['room', 'assignments.user'])
            ->latest()
            ->get();
    }

    public function byStatus(TaskStatusEnum $status): Collection
    {
        return CleaningTask::where('status', $status)
            ->with(['room', 'zone'])
            ->latest()
            ->get();
    }

    public function dueToday(): Collection
    {
        return CleaningTask::whereDate('due_date', today())
            ->whereNotIn('status', [
                TaskStatusEnum::Completed->value,
                TaskStatusEnum::Cancelled->value,
            ])
            ->with(['room', 'zone', 'assignments.user'])
            ->orderBy('priority')
            ->get();
    }

    public function overdue(): Collection
    {
        return CleaningTask::where('due_date', '<', now())
            ->whereNotIn('status', [
                TaskStatusEnum::Completed->value,
                TaskStatusEnum::Cancelled->value,
            ])
            ->with(['room', 'zone', 'assignments.user'])
            ->orderBy('priority')
            ->orderBy('due_date')
            ->get();
    }

    public function completedToday(): Collection
    {
        return CleaningTask::whereNotNull('completed_at')
            ->whereDate('completed_at', today())
            ->with(['room', 'zone', 'completedBy'])
            ->latest('completed_at')
            ->get();
    }
}

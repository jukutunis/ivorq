<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Shared\Exceptions\NotFoundException;

class PreventiveMaintenanceTaskRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PreventiveMaintenanceTask::with(['preventiveMaintenance', 'workOrder'])->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['preventive_maintenance_id'])) {
            $query->where('preventive_maintenance_id', $filters['preventive_maintenance_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): PreventiveMaintenanceTask
    {
        $task = PreventiveMaintenanceTask::with([
            'preventiveMaintenance',
            'workOrder',
            'completedBy',
        ])->find($id);

        throw_if(! $task, new NotFoundException('PreventiveMaintenanceTask'));

        return $task;
    }

    public function create(array $data): PreventiveMaintenanceTask
    {
        return PreventiveMaintenanceTask::create($data)->fresh();
    }

    public function update(string $id, array $data): PreventiveMaintenanceTask
    {
        $task = $this->find($id);
        $task->update($data);

        return $task->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    /**
     * Returns all non-terminal PM tasks (scheduled, assigned, in_progress, overdue).
     * Used by the dashboard and scheduling engine to surface outstanding work.
     */
    public function pending(): Collection
    {
        return PreventiveMaintenanceTask::whereNotIn('status', [
            PmTaskStatusEnum::Completed->value,
            PmTaskStatusEnum::Skipped->value,
        ])
            ->with(['preventiveMaintenance', 'workOrder'])
            ->orderBy('scheduled_date')
            ->get();
    }

    public function completedToday(): Collection
    {
        return PreventiveMaintenanceTask::where('status', PmTaskStatusEnum::Completed)
            ->whereDate('completed_at', today())
            ->with(['preventiveMaintenance', 'completedBy'])
            ->latest('completed_at')
            ->get();
    }
}

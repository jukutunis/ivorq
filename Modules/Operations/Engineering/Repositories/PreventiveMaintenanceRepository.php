<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Shared\Exceptions\NotFoundException;

class PreventiveMaintenanceRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PreventiveMaintenance::with(['room', 'zone', 'department'])
            ->withCount('tasks')
            ->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['frequency'])) {
            $query->where('frequency', $filters['frequency']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): PreventiveMaintenance
    {
        $pm = PreventiveMaintenance::with([
            'room',
            'zone',
            'department',
            'tasks.workOrder',
        ])->find($id);

        throw_if(! $pm, new NotFoundException('PreventiveMaintenance'));

        return $pm;
    }

    public function create(array $data): PreventiveMaintenance
    {
        return PreventiveMaintenance::create($data)->fresh();
    }

    public function update(string $id, array $data): PreventiveMaintenance
    {
        $pm = $this->find($id);
        $pm->update($data);

        return $pm->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function dueToday(): Collection
    {
        return PreventiveMaintenance::where('status', PmStatusEnum::Active)
            ->whereDate('next_due_at', today())
            ->with(['room', 'zone', 'department'])
            ->orderBy('next_due_at')
            ->get();
    }

    public function overdue(): Collection
    {
        return PreventiveMaintenance::where('status', PmStatusEnum::Active)
            ->where('next_due_at', '<', now()->startOfDay())
            ->with(['room', 'zone', 'department'])
            ->orderBy('next_due_at')
            ->get();
    }

    public function active(): Collection
    {
        return PreventiveMaintenance::where('status', PmStatusEnum::Active)
            ->with(['room', 'zone', 'department'])
            ->orderBy('next_due_at')
            ->get();
    }
}

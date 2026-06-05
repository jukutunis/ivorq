<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Models\WorkOrder;
use Shared\Exceptions\NotFoundException;

class WorkOrderRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = WorkOrder::with(['room', 'zone'])->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['work_order_type'])) {
            $query->where('work_order_type', $filters['work_order_type']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (! empty($filters['zone_id'])) {
            $query->where('zone_id', $filters['zone_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): WorkOrder
    {
        $workOrder = WorkOrder::with([
            'room',
            'zone',
            'assignments.user',
            'assignments.department',
            'statusHistories.changedBy',
            'assetRequests',
        ])->find($id);

        throw_if(! $workOrder, new NotFoundException('WorkOrder'));

        return $workOrder;
    }

    public function findOrFail(string $id): WorkOrder
    {
        return WorkOrder::findOrFail($id);
    }

    public function create(array $data): WorkOrder
    {
        return WorkOrder::create($data)->fresh();
    }

    public function update(string $id, array $data): WorkOrder
    {
        $workOrder = $this->find($id);
        $workOrder->update($data);

        return $workOrder->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function byStatus(WorkOrderStatusEnum $status): Collection
    {
        return WorkOrder::where('status', $status)
            ->with(['room', 'zone'])
            ->orderBy('priority')
            ->latest()
            ->get();
    }

    public function byPriority(WorkOrderPriorityEnum $priority): Collection
    {
        return WorkOrder::where('priority', $priority->value)
            ->with(['room', 'zone'])
            ->whereNotIn('status', [
                WorkOrderStatusEnum::Completed->value,
                WorkOrderStatusEnum::Cancelled->value,
            ])
            ->latest()
            ->get();
    }

    public function overdue(): Collection
    {
        return WorkOrder::where('due_date', '<', now())
            ->whereNotIn('status', [
                WorkOrderStatusEnum::Completed->value,
                WorkOrderStatusEnum::Cancelled->value,
            ])
            ->with(['room', 'zone'])
            ->orderBy('priority')
            ->orderBy('due_date')
            ->get();
    }

    public function open(): Collection
    {
        return WorkOrder::whereNotIn('status', [
            WorkOrderStatusEnum::Completed->value,
            WorkOrderStatusEnum::Cancelled->value,
        ])
            ->with(['room', 'zone'])
            ->orderBy('priority')
            ->orderBy('due_date')
            ->get();
    }

    public function completedToday(): Collection
    {
        return WorkOrder::where('status', WorkOrderStatusEnum::Completed)
            ->whereDate('completed_at', today())
            ->with(['room', 'zone'])
            ->latest('completed_at')
            ->get();
    }
}

<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Shared\Exceptions\NotFoundException;

class TechnicianAssignmentRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = TechnicianAssignment::with(['workOrder', 'user', 'department'])->latest();

        if (! empty($filters['work_order_id'])) {
            $query->where('work_order_id', $filters['work_order_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): TechnicianAssignment
    {
        $assignment = TechnicianAssignment::with([
            'workOrder',
            'user',
            'department',
            'assignedBy',
        ])->find($id);

        throw_if(! $assignment, new NotFoundException('TechnicianAssignment'));

        return $assignment;
    }

    public function create(array $data): TechnicianAssignment
    {
        return TechnicianAssignment::create($data)->fresh();
    }

    public function update(string $id, array $data): TechnicianAssignment
    {
        $assignment = $this->find($id);
        $assignment->update($data);

        return $assignment->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function activeForWorkOrder(string $workOrderId): Collection
    {
        return TechnicianAssignment::where('work_order_id', $workOrderId)
            ->where('status', TechnicianAssignmentStatusEnum::Active)
            ->with(['user', 'department'])
            ->latest('assigned_at')
            ->get();
    }

    public function activeForUser(string $userId): Collection
    {
        return TechnicianAssignment::where('user_id', $userId)
            ->where('status', TechnicianAssignmentStatusEnum::Active)
            ->with(['workOrder.room', 'workOrder.zone'])
            ->latest('assigned_at')
            ->get();
    }
}

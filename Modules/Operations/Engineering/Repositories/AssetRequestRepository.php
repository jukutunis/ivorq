<?php

namespace Modules\Operations\Engineering\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Models\AssetRequest;
use Shared\Exceptions\NotFoundException;

class AssetRequestRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = AssetRequest::with(['workOrder', 'requester', 'department'])->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['work_order_id'])) {
            $query->where('work_order_id', $filters['work_order_id']);
        }

        if (! empty($filters['requester_id'])) {
            $query->where('requester_id', $filters['requester_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): AssetRequest
    {
        $request = AssetRequest::with([
            'workOrder',
            'requester',
            'department',
            'approvedBy',
            'rejectedBy',
            'fulfilledBy',
        ])->find($id);

        throw_if(! $request, new NotFoundException('AssetRequest'));

        return $request;
    }

    public function create(array $data): AssetRequest
    {
        return AssetRequest::create($data)->fresh();
    }

    public function update(string $id, array $data): AssetRequest
    {
        $request = $this->find($id);
        $request->update($data);

        return $request->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function pendingApproval(): Collection
    {
        return AssetRequest::where('status', AssetRequestStatusEnum::Pending)
            ->with(['workOrder', 'requester', 'department'])
            ->orderBy('priority')
            ->latest()
            ->get();
    }

    public function approved(): Collection
    {
        return AssetRequest::where('status', AssetRequestStatusEnum::Approved)
            ->with(['workOrder', 'requester', 'department', 'approvedBy'])
            ->latest()
            ->get();
    }

    public function fulfilled(): Collection
    {
        return AssetRequest::where('status', AssetRequestStatusEnum::Fulfilled)
            ->with(['workOrder', 'requester', 'fulfilledBy'])
            ->latest('fulfilled_at')
            ->get();
    }
}

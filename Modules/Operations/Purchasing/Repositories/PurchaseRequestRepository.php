<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Shared\Exceptions\NotFoundException;

class PurchaseRequestRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseRequest::with(['department', 'requester', 'lines.inventoryItem', 'lines.unit'])->latest();

        if (! empty($filters['request_no'])) {
            $query->where('request_no', 'like', '%' . $filters['request_no'] . '%');
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): PurchaseRequest
    {
        $pr = PurchaseRequest::with(['department', 'requester', 'lines.inventoryItem', 'lines.unit'])->find($id);

        throw_if(! $pr, new NotFoundException('PurchaseRequest'));

        return $pr;
    }

    public function findOrFail(string $id): PurchaseRequest
    {
        return PurchaseRequest::findOrFail($id);
    }

    public function create(array $data): PurchaseRequest
    {
        return PurchaseRequest::create($data)->fresh(['department', 'requester']);
    }

    public function update(string $id, array $data): PurchaseRequest
    {
        $pr = $this->find($id);
        $pr->update($data);

        return $pr->fresh(['department', 'requester', 'lines']);
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }
}

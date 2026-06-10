<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Shared\Exceptions\NotFoundException;

class PurchaseOrderRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['vendor', 'purchaseRequest', 'lines.inventoryItem', 'lines.unit'])->latest();

        if (! empty($filters['po_no'])) {
            $query->where('po_no', 'like', '%' . $filters['po_no'] . '%');
        }

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): PurchaseOrder
    {
        $po = PurchaseOrder::with(['vendor', 'purchaseRequest', 'lines.inventoryItem', 'lines.unit'])->find($id);

        throw_if(! $po, new NotFoundException('PurchaseOrder'));

        return $po;
    }

    public function findOrFail(string $id): PurchaseOrder
    {
        return PurchaseOrder::findOrFail($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data)->fresh(['vendor', 'purchaseRequest']);
    }

    public function update(string $id, array $data): PurchaseOrder
    {
        $po = $this->find($id);
        $po->update($data);

        return $po->fresh(['vendor', 'purchaseRequest', 'lines']);
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }
}

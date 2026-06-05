<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Shared\Exceptions\NotFoundException;

class InventoryReceiptRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryReceipt::latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_name'])) {
            $query->where('supplier_name', 'like', '%' . $filters['supplier_name'] . '%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryReceipt
    {
        $receipt = InventoryReceipt::with([
            'lines.item.unit',
            'lines.location',
            'postedBy',
            'cancelledBy',
        ])->find($id);

        throw_if(! $receipt, new NotFoundException('InventoryReceipt'));

        return $receipt;
    }

    public function findOrFail(string $id): InventoryReceipt
    {
        return InventoryReceipt::findOrFail($id);
    }

    public function create(array $data): InventoryReceipt
    {
        return InventoryReceipt::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryReceipt
    {
        $receipt = $this->find($id);
        $receipt->update($data);

        return $receipt->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function byStatus(ReceiptStatusEnum $status): Collection
    {
        return InventoryReceipt::where('status', $status)
            ->latest()
            ->get();
    }
}

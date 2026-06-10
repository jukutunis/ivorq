<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Purchasing\Models\GoodsReceipt;
use Shared\Exceptions\NotFoundException;

class GoodsReceiptRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = GoodsReceipt::with(['purchaseOrder', 'vendor', 'lines.inventoryItem', 'lines.location'])->latest();

        if (! empty($filters['grn_no'])) {
            $query->where('grn_no', 'like', '%' . $filters['grn_no'] . '%');
        }

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): GoodsReceipt
    {
        $grn = GoodsReceipt::with(['purchaseOrder', 'vendor', 'lines.inventoryItem', 'lines.location'])->find($id);

        throw_if(! $grn, new NotFoundException('GoodsReceipt'));

        return $grn;
    }

    public function findOrFail(string $id): GoodsReceipt
    {
        return GoodsReceipt::findOrFail($id);
    }

    public function create(array $data): GoodsReceipt
    {
        return GoodsReceipt::create($data)->fresh(['vendor', 'purchaseOrder']);
    }

    public function update(string $id, array $data): GoodsReceipt
    {
        $grn = $this->find($id);
        $grn->update($data);

        return $grn->fresh(['vendor', 'purchaseOrder', 'lines']);
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }
}

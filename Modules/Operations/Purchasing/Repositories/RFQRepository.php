<?php

namespace Modules\Operations\Purchasing\Repositories;

use Modules\Operations\Purchasing\Models\RFQ;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class RFQRepository
{
    public function findById(string $id): ?RFQ
    {
        return RFQ::with(['vendors', 'quotations'])->find($id);
    }

    public function create(array $data): RFQ
    {
        return RFQ::create($data);
    }

    public function update(RFQ $rfq, array $data): bool
    {
        return $rfq->update($data);
    }

    public function delete(RFQ $rfq): bool
    {
        return $rfq->delete();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return RFQ::with(['purchaseRequest'])
            ->latest()
            ->paginate($perPage);
    }
}

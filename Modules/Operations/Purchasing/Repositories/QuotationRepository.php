<?php

namespace Modules\Operations\Purchasing\Repositories;

use Modules\Operations\Purchasing\Models\Quotation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class QuotationRepository
{
    public function findById(string $id): ?Quotation
    {
        return Quotation::with(['lines', 'vendor'])->find($id);
    }

    public function create(array $data): Quotation
    {
        return Quotation::create($data);
    }

    public function update(Quotation $quotation, array $data): bool
    {
        return $quotation->update($data);
    }

    public function delete(Quotation $quotation): bool
    {
        return $quotation->delete();
    }

    public function getByRfqId(string $rfqId): Collection
    {
        return Quotation::where('rfq_id', $rfqId)
            ->with(['vendor', 'lines'])
            ->get();
    }
}

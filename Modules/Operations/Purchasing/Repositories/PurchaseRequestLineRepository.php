<?php

namespace Modules\Operations\Purchasing\Repositories;

use Modules\Operations\Purchasing\Models\PurchaseRequestLine;
use Shared\Exceptions\NotFoundException;

class PurchaseRequestLineRepository
{
    public function find(string $id): PurchaseRequestLine
    {
        $line = PurchaseRequestLine::find($id);

        throw_if(! $line, new NotFoundException('PurchaseRequestLine'));

        return $line;
    }

    public function create(array $data): PurchaseRequestLine
    {
        return PurchaseRequestLine::create($data)->fresh();
    }

    public function update(string $id, array $data): PurchaseRequestLine
    {
        $line = $this->find($id);
        $line->update($data);

        return $line->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function deleteByPurchaseRequestId(string $purchaseRequestId): bool
    {
        return PurchaseRequestLine::where('purchase_request_id', $purchaseRequestId)->delete();
    }
}

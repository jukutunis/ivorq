<?php

namespace Modules\Operations\Receiving\Repositories;

use Modules\Operations\Receiving\Models\ReceivingDiscrepancy;

class ReceivingDiscrepancyRepository
{
    public function create(array $data): ReceivingDiscrepancy
    {
        return ReceivingDiscrepancy::create($data);
    }

    public function findById(string $id): ?ReceivingDiscrepancy
    {
        return ReceivingDiscrepancy::find($id);
    }
}

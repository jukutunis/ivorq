<?php

namespace Modules\Operations\Receiving\Repositories;

use Modules\Operations\Receiving\Models\ReceivingInspection;

class ReceivingInspectionRepository
{
    public function create(array $data): ReceivingInspection
    {
        return ReceivingInspection::create($data);
    }

    public function findById(string $id): ?ReceivingInspection
    {
        return ReceivingInspection::find($id);
    }
}

<?php

namespace Modules\Operations\Receiving\Repositories;

use Modules\Operations\Receiving\Models\ReceivingLine;

class ReceivingLineRepository
{
    public function create(array $data): ReceivingLine
    {
        return ReceivingLine::create($data);
    }

    public function findById(string $id): ?ReceivingLine
    {
        return ReceivingLine::find($id);
    }
}

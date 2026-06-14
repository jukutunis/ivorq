<?php

namespace Modules\Operations\Receiving\Repositories;

use Modules\Operations\Receiving\Models\ReceivingDocument;

class ReceivingRepository
{
    public function create(array $data): ReceivingDocument
    {
        return ReceivingDocument::create($data);
    }

    public function findById(string $id): ?ReceivingDocument
    {
        return ReceivingDocument::find($id);
    }
}

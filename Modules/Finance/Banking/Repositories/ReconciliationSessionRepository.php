<?php

namespace Modules\Finance\Banking\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Banking\Models\ReconciliationSession;

class ReconciliationSessionRepository
{
    public function query(): Builder
    {
        return ReconciliationSession::query();
    }

    public function find(string $id): ?ReconciliationSession
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): ReconciliationSession
    {
        return $this->query()->findOrFail($id);
    }

    public function lockForUpdate(string $id): ReconciliationSession
    {
        return $this->query()->lockForUpdate()->findOrFail($id);
    }

    public function create(array $data): ReconciliationSession
    {
        return ReconciliationSession::create($data);
    }
}

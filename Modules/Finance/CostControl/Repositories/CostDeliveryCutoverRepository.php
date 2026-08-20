<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Models\CostDeliveryCutover;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverAttempt;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverScope;
use RuntimeException;

class CostDeliveryCutoverRepository
{
    public function insertCutoverEvidence(array $attributes): CostDeliveryCutover
    {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryCutover::create($attributes);
    }

    public function insertScopeEvidence(array $attributes): CostDeliveryCutoverScope
    {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryCutoverScope::create($attributes);
    }

    public function insertAttemptEvidence(array $attributes): CostDeliveryCutoverAttempt
    {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryCutoverAttempt::create($attributes);
    }

    public function findForUpdateByOwnership(string $ownershipId): ?CostDeliveryCutover
    {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryCutover::where('ownership_id', $ownershipId)
            ->lockForUpdate()
            ->first();
    }

    private function requireTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException("{$method} requires an active outer transaction.");
        }
    }
}

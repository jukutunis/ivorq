<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use RuntimeException;

class CostDeliveryModeOwnershipRepository
{
    public function findForUpdateByEnrollmentGroup(string $enrollmentGroupId): ?CostDeliveryModeOwnership
    {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryModeOwnership::where('enrollment_group_id', $enrollmentGroupId)
            ->lockForUpdate()
            ->first();
    }

    public function findForUpdateByPropertyItem(
        string $propertyId,
        string $itemId
    ): ?CostDeliveryModeOwnership {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryModeOwnership::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();
    }

    public function createInitialSynchronous(
        CostAuthorityEnrollmentGroup $group,
        string $actorId
    ): CostDeliveryModeOwnership {
        $this->requireTransaction(__METHOD__);

        return CostDeliveryModeOwnership::create([
            'property_id' => $group->property_id,
            'item_id' => $group->item_id,
            'enrollment_group_id' => $group->id,
            'delivery_mode' => CostDeliveryMode::Synchronous,
            'ownership_version' => 1,
            'activated_cutover_id' => null,
            'established_by' => $actorId,
            'established_at' => now(),
            'changed_by' => null,
            'changed_at' => null,
        ]);
    }

    public function isExactInitialSynchronous(
        CostDeliveryModeOwnership $ownership,
        CostAuthorityEnrollmentGroup $group
    ): bool {
        return $ownership->property_id === $group->property_id
            && $ownership->item_id === $group->item_id
            && $ownership->enrollment_group_id === $group->id
            && $ownership->delivery_mode === CostDeliveryMode::Synchronous
            && $ownership->ownership_version === 1
            && $ownership->activated_cutover_id === null
            && $ownership->established_by !== null
            && trim((string) $ownership->established_by) !== ''
            && $ownership->established_at !== null
            && $ownership->changed_by === null
            && $ownership->changed_at === null;
    }

    private function requireTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException("{$method} requires an active outer transaction.");
        }
    }
}

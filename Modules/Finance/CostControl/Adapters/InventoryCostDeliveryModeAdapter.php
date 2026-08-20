<?php

namespace Modules\Finance\CostControl\Adapters;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use RuntimeException;

class InventoryCostDeliveryModeAdapter implements CostDeliveryModePort
{
    public function __construct(
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
    ) {}

    public function resolveForPosting(
        string $propertyId,
        string $itemId,
        string $locationId
    ): CostDeliveryPostingDecision {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $group = CostAuthorityEnrollmentGroup::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where('status', CostAuthorityEnrollmentStatusEnum::Enrolled->value)
            ->lockForUpdate()
            ->first();

        if ($group === null) {
            return CostDeliveryPostingDecision::notEnrolled($propertyId, $itemId);
        }

        $expectedScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
        $scopeExists = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)
            ->where('location_id', $locationId)
            ->where('valuation_scope', $expectedScope)
            ->exists();

        if (! $scopeExists) {
            throw new RuntimeException('ENROLLED_DELIVERY_SCOPE_MISSING');
        }

        $ownership = $this->ownershipRepository->findForUpdateByEnrollmentGroup($group->id);
        if ($ownership === null) {
            throw new RuntimeException('ENROLLED_DELIVERY_OWNERSHIP_MISSING');
        }

        if ($ownership->property_id !== $propertyId
            || $ownership->item_id !== $itemId
            || $ownership->enrollment_group_id !== $group->id) {
            throw new RuntimeException('ENROLLED_DELIVERY_OWNERSHIP_MISMATCH');
        }

        return match ($ownership->delivery_mode) {
            CostDeliveryMode::Synchronous => CostDeliveryPostingDecision::synchronous(
                $propertyId,
                $itemId,
                $ownership->id,
                $ownership->ownership_version,
            ),
            CostDeliveryMode::Deferred => CostDeliveryPostingDecision::deferred(
                $propertyId,
                $itemId,
                $ownership->id,
                $ownership->ownership_version,
                $ownership->activated_cutover_id
                    ?? throw new RuntimeException('DEFERRED_DELIVERY_CUTOVER_MISSING'),
            ),
        };
    }
}

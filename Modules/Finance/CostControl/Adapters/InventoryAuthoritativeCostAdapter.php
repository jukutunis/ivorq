<?php

namespace Modules\Finance\CostControl\Adapters;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Operations\Inventory\Contracts\AuthoritativeInventoryCostPort;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use RuntimeException;

final class InventoryAuthoritativeCostAdapter implements AuthoritativeInventoryCostPort
{
    public function resolveUnitCostForPosting(CostDeliveryPostingDecision $prelockedDecision): string
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        if ($prelockedDecision->outcome === CostDeliveryPostingDecision::NOT_ENROLLED
            || $prelockedDecision->ownershipId === null
            || $prelockedDecision->ownershipVersion === null
            || $prelockedDecision->locationId === null) {
            throw new RuntimeException('CC_P01F_COST_AUTHORITY_DECISION_INVALID');
        }
        $ownership = CostDeliveryModeOwnership::find($prelockedDecision->ownershipId);
        if ($ownership === null || ! in_array($ownership->delivery_mode, [
            CostDeliveryMode::Synchronous,
            CostDeliveryMode::Deferred,
        ], true)
            || $ownership->property_id !== $prelockedDecision->propertyId
            || $ownership->item_id !== $prelockedDecision->itemId
            || (int) $ownership->ownership_version !== $prelockedDecision->ownershipVersion
            || $ownership->delivery_mode->value !== $prelockedDecision->deliveryMode
            || $ownership->activated_cutover_id !== $prelockedDecision->cutoverId) {
            throw new RuntimeException('CC_P01F_COST_AUTHORITY_OWNERSHIP_INVALID');
        }

        // The producer already holds the ownership latch supplied in the
        // decision. Read the cost basis without taking AVCO ahead of sequence.
        $state = CostAvcoState::where('property_id', $prelockedDecision->propertyId)
            ->where('location_id', $prelockedDecision->locationId)
            ->where('item_id', $prelockedDecision->itemId)
            ->firstOrFail();
        if ($state->enrollment_group_id !== $ownership->enrollment_group_id
            || $state->weighted_average_unit_cost === null
            || bccomp((string) $state->on_hand_quantity, '0', 4) <= 0
            || bccomp((string) $state->weighted_average_unit_cost, '0', 4) <= 0) {
            throw new RuntimeException('CC_P01F_AUTHORITATIVE_UNIT_COST_UNAVAILABLE');
        }

        return (string) $state->weighted_average_unit_cost;
    }
}

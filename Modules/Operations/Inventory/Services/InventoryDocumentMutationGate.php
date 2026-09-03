<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use RuntimeException;

final class InventoryDocumentMutationGate
{
    public function __construct(private readonly CostDeliveryModePort $costDeliveryMode) {}

    /** @param array<int,string|int|null> $itemIds */
    public function lock(string $propertyId, array $itemIds): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        sort($itemIds, SORT_STRING);
        foreach ($itemIds as $itemId) {
            $this->costDeliveryMode->lockForDocumentMutation($propertyId, $itemId);
        }
    }
}

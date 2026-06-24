<?php

namespace Modules\Operations\Inventory\Services;

class AvcoValuationCalculator
{
    /**
     * Calculates the new Weighted Average Cost (AVCO) for an item.
     *
     * @param float $oldQty The total physical quantity of the item before the receipt.
     * @param float $oldWac The previous weighted average cost of the item.
     * @param float $receiptQty The total quantity received for the item.
     * @param float $receiptValue The total monetary value received for the item.
     * @return float The newly calculated weighted average cost.
     */
    public function calculate(float $oldQty, float $oldWac, float $receiptQty, float $receiptValue): float
    {
        $newTotalQty = $oldQty + $receiptQty;

        if ($newTotalQty <= 0) {
            return 0.0;
        }

        return (($oldQty * $oldWac) + $receiptValue) / $newTotalQty;
    }
}

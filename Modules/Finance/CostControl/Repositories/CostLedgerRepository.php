<?php

namespace Modules\Finance\CostControl\Repositories;

use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostLedgerEntry;

class CostLedgerRepository
{
    private const REQUIRED_FIELDS = [
        'property_id',
        'location_id',
        'item_id',
        'valuation_scope',
        'valuation_sequence',
        'inventory_transaction_id',
        'business_date',
        'quantity_before',
        'quantity_after',
        'carrying_value_before',
        'carrying_value_after',
        'weighted_average_unit_cost_after',
    ];

    /**
     * Append one immutable Cost Ledger entry.
     *
     * Accepts only an explicit full ledger row. All required fields must be
     * present. valuation_sequence must be positive. No float arithmetic is
     * performed. Duplicate constraint violations propagate as-is with no retry.
     */
    public function append(array $attributes): CostLedgerEntry
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $attributes)) {
                throw new InvalidArgumentException(
                    "CostLedgerRepository::append requires field: {$field}."
                );
            }
        }

        if ((int) $attributes['valuation_sequence'] <= 0) {
            throw new InvalidArgumentException(
                "CostLedgerRepository::append: valuation_sequence must be positive. " .
                "Got: {$attributes['valuation_sequence']}."
            );
        }

        return CostLedgerEntry::create($attributes);
    }

    /**
     * Return the Cost Ledger entry sourced from the given InventoryTransaction,
     * or null if none has been appended yet.
     */
    public function findByInventoryTransactionId(string $inventoryTransactionId): ?CostLedgerEntry
    {
        return CostLedgerEntry::where('inventory_transaction_id', $inventoryTransactionId)->first();
    }
}

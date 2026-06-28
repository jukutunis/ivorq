<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationApplyCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;

/**
 * Invocation service for multi-line adjustments under controlled valuation.
 */
final class ControlledAdjustmentValuationInvocationService
{
    public function __construct(
        private readonly CostAvcoStateRepository $stateRepository,
        private readonly InventoryPostingControlCoordinator $postingCoordinator,
        private readonly ControlledAdjustmentValuationApplyCoordinator $applyCoordinator
    ) {}

    /**
     * Coordinate and apply valuation changes for an entire all-enrolled adjustment document.
     */
    public function invokeAdjustmentDocument(
        string $propertyId,
        iterable $sortedLines,
        string $locationId,
        string $businessDate,
        \Illuminate\Support\Carbon $occurredAt,
        string $actorId,
        string $adjustmentId,
        string $adjustmentNumber
    ): void {
        // Derive complete unique state scopes
        $uniqueScopes = [];
        foreach ($sortedLines as $line) {
            $itemId = $line->item_id;
            $scopeKey = "{$itemId}:{$locationId}";
            $uniqueScopes[$scopeKey] = [
                'itemId' => $itemId,
                'locationId' => $locationId,
            ];
        }

        // Exact global lock query
        $lockedStatesMap = $this->stateRepository->lockExistingSeededStateSetForAdjustmentScopes(
            $propertyId,
            array_values($uniqueScopes)
        );

        // Process lines in existing operational document order
        foreach ($sortedLines as $line) {
            $itemId = $line->item_id;
            $variance = (float) $line->quantity_variance;
            if ($variance == 0) {
                continue;
            }

            $canonicalKey = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
            $lockedState = $lockedStatesMap[$canonicalKey] ?? null;
            if (!$lockedState) {
                throw new \RuntimeException("Locked state not found for scope {$canonicalKey}.");
            }

            // Establish valuation authority and transaction type
            if ($variance > 0) {
                // AdjustmentIn
                $type = TransactionTypeEnum::AdjustmentIn;
                $costToUse = $line->unit_cost;
                if ($costToUse === null || (float)$costToUse <= 0) {
                    throw new \RuntimeException("Unit cost is required and must be positive for positive adjustment.");
                }
                $qtyChange = (string) $variance;
                $totalCost = bcmul($qtyChange, (string)$costToUse, 4);
            } else {
                // AdjustmentOut
                $type = TransactionTypeEnum::AdjustmentOut;
                if ($lockedState->weighted_average_unit_cost === null || (float)$lockedState->weighted_average_unit_cost <= 0) {
                    throw new \RuntimeException("Locked state WAUC is missing or non-positive on AdjustmentOut.");
                }
                $costToUse = (string) $lockedState->weighted_average_unit_cost;
                $qtyChange = (string) $variance;
                $totalCost = bcmul($qtyChange, $costToUse, 4);
            }

            // Create canonical immutable InventoryTransaction
            $intent = new InventoryLedgerPostingIntent(
                propertyId: $propertyId,
                itemId: $itemId,
                locationId: $locationId,
                businessDate: $businessDate,
                occurredAt: $occurredAt,
                sourceDocumentType: 'inventory_adjustment',
                sourceDocumentId: $adjustmentId,
                sourceLineType: 'inventory_adjustment_line',
                sourceLineId: $line->id,
                movementRole: $type->value,
                idempotencyKey: "adj_{$adjustmentId}_{$line->id}_approve",
                transactionType: $type,
                quantityChange: $qtyChange,
                unitCost: $costToUse,
                totalCost: $totalCost,
                reference: $adjustmentNumber,
                notes: 'Inventory Adjustment Posting'
            );

            $transaction = $this->postingCoordinator->post($intent, $actorId);

            // Derive ControlledValuationCostLedgerIntent from transaction evidence
            $costLedgerIntent = new ControlledValuationCostLedgerIntent(
                propertyId: $propertyId,
                sourceInventoryTransactionId: $transaction->id,
                priorCostLedgerEntryId: null,
                entryType: 'adjustment',
                idempotencyKey: $transaction->idempotency_key,
                entrySequence: $transaction->valuation_sequence,
                currencyCode: $transaction->currency_code,
                quantityDelta: new AvcoDecimal((string)$transaction->quantity_change),
                unitCost: new AvcoDecimal((string)$transaction->unit_cost),
                valueDelta: new AvcoDecimal((string)$transaction->total_cost),
                businessDate: $transaction->business_date->format('Y-m-d'),
                occurredAt: $transaction->occurred_at->format('Y-m-d H:i:s')
            );

            // Reconstruct prior sequence for plan
            $priorSequence = null;
            if ($lockedState->last_valuation_sequence !== null && $lockedState->last_valuation_business_date !== null) {
                $priorSequence = new ValuationSequence(
                    propertyId: $propertyId,
                    itemId: $itemId,
                    valuationScope: $lockedState->valuation_scope,
                    businessDate: $lockedState->last_valuation_business_date->format('Y-m-d'),
                    ledgerSequence: (int)$lockedState->last_valuation_sequence
                );
            }

            $authoritativeIntent = new ControlledAdjustmentValuationIntent(
                propertyId: $propertyId,
                locationId: $locationId,
                itemId: $itemId,
                currentLastAppliedValuationSequence: $priorSequence,
                currentQuantity: new AvcoDecimal((string)$lockedState->on_hand_quantity),
                currentCarryingValue: new AvcoDecimal((string)$lockedState->carrying_value),
                costLedgerIntent: $costLedgerIntent
            );

            // Apply and persist state using same in-memory locked state
            $this->applyCoordinator->applyUsingLockedState($lockedState, $authoritativeIntent);

            // Fire operational completion events
            \Modules\Operations\Inventory\Events\InventoryAdjustmentPosted::dispatch($transaction);
        }
    }
}

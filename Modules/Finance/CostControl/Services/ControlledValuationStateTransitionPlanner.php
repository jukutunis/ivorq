<?php

namespace Modules\Finance\CostControl\Services;

use InvalidArgumentException;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionPlan;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

/**
 * Pure, inactive, receipt-only valuation planner that calculates future state transitions
 * without database access or side-effects.
 */
class ControlledValuationStateTransitionPlanner
{
    /**
     * Plan a future controlled valuation state transition based on the incoming intent.
     *
     * @param ControlledValuationStateTransitionIntent $intent
     * @return ControlledValuationStateTransitionPlan
     * @throws InvalidArgumentException
     */
    public function plan(
        ControlledValuationStateTransitionIntent $intent
    ): ControlledValuationStateTransitionPlan {
        $ledgerIntent = $intent->costLedgerIntent;

        // 1. Receipt-only type validation
        if ($ledgerIntent->entryType !== 'receipt') {
            throw new InvalidArgumentException(
                sprintf('Unsupported valuation movement type "%s". Only "receipt" is supported.', $ledgerIntent->entryType)
            );
        }

        // 2. Receipt quantity and value validation
        if (!$ledgerIntent->quantityDelta->isPositive()) {
            throw new InvalidArgumentException('Receipt quantity delta must be positive.');
        }

        if ($ledgerIntent->valueDelta->isNegative()) {
            throw new InvalidArgumentException('Receipt value delta cannot be negative.');
        }

        // 3. Resulting quantity validation
        $quantityAfter = $intent->currentQuantity->add($ledgerIntent->quantityDelta);
        if (!$quantityAfter->isPositive()) {
            throw new InvalidArgumentException('Resulting quantity must be positive.');
        }

        // 4. Source and idempotency evidence validation
        if (trim($ledgerIntent->sourceInventoryTransactionId) === '') {
            throw new InvalidArgumentException('Source inventory transaction reference cannot be blank.');
        }

        if (trim($ledgerIntent->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key cannot be blank.');
        }

        // 5. Valuation sequence progression and chronological ordering validation
        $nextSequence = new ValuationSequence(
            propertyId: $intent->propertyId,
            itemId: $intent->itemId,
            valuationScope: $intent->valuationScope,
            businessDate: $ledgerIntent->businessDate,
            ledgerSequence: $ledgerIntent->entrySequence
        );

        if ($intent->currentLastAppliedValuationSequence === null) {
            // No sequence has been applied yet. The first sequence must be exactly 1.
            if ($ledgerIntent->entrySequence !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Sequence gap detected. First sequence must be 1, got %d.', $ledgerIntent->entrySequence)
                );
            }
        } else {
            $lastSeq = $intent->currentLastAppliedValuationSequence;

            // Enforce strict chronological ordering and sequence increment (no-gap rule)
            if ($ledgerIntent->entrySequence <= $lastSeq->ledgerSequence) {
                throw new InvalidArgumentException('Stale or duplicate sequence detected.');
            }

            if ($ledgerIntent->entrySequence !== $lastSeq->ledgerSequence + 1) {
                throw new InvalidArgumentException(
                    sprintf('Sequence gap detected. Expected sequence %d, got %d.', $lastSeq->ledgerSequence + 1, $ledgerIntent->entrySequence)
                );
            }

            if ($nextSequence->compareTo($lastSeq) <= 0) {
                throw new InvalidArgumentException('Sequence out of order chronologically.');
            }
        }

        // 6. AVCO receipt calculation
        $carryingValueAfter = $intent->currentCarryingValue->add($ledgerIntent->valueDelta);
        $waucAfter = $carryingValueAfter->div($quantityAfter);

        // 7. Produce plan
        return new ControlledValuationStateTransitionPlan(
            valuationScope: $intent->valuationScope,
            quantityBefore: $intent->currentQuantity,
            quantityAfter: $quantityAfter,
            carryingValueBefore: $intent->currentCarryingValue,
            carryingValueAfter: $carryingValueAfter,
            weightedAverageUnitCostAfter: $waucAfter,
            lastAppliedValuationSequenceBefore: $intent->currentLastAppliedValuationSequence,
            lastAppliedValuationSequenceAfter: $nextSequence,
            costLedgerIntent: $ledgerIntent
        );
    }
}

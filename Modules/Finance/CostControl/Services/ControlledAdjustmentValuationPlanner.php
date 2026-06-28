<?php

namespace Modules\Finance\CostControl\Services;

use InvalidArgumentException;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

/**
 * Pure service to plan and validate adjustment valuation transitions without database access.
 */
final class ControlledAdjustmentValuationPlanner
{
    /**
     * Plan a future controlled adjustment valuation state transition based on the incoming intent.
     */
    public function plan(
        ControlledAdjustmentValuationIntent $intent
    ): ControlledAdjustmentValuationPlan {
        $ledgerIntent = $intent->costLedgerIntent;

        // 1. Entry type validation
        if ($ledgerIntent->entryType !== 'adjustment') {
            throw new InvalidArgumentException(
                sprintf('Unsupported valuation movement type "%s". Only "adjustment" is supported.', $ledgerIntent->entryType)
            );
        }

        // 2. Source and idempotency evidence validation
        if (trim($ledgerIntent->sourceInventoryTransactionId) === '') {
            throw new InvalidArgumentException('Source inventory transaction reference cannot be blank.');
        }

        if (trim($ledgerIntent->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key cannot be blank.');
        }

        // 3. Valuation sequence progression and chronological ordering validation
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

        if ($ledgerIntent->quantityDelta->isZero()) {
            throw new InvalidArgumentException('Adjustment quantity delta cannot be zero.');
        }

        if ($ledgerIntent->quantityDelta->isPositive()) {
            // Positive adjustment (AdjustmentIn)
            if ($ledgerIntent->valueDelta->isNegative()) {
                throw new InvalidArgumentException('Positive adjustment value delta cannot be negative.');
            }

            if (!$ledgerIntent->unitCost->isPositive()) {
                throw new InvalidArgumentException('Positive adjustment unit cost must be positive.');
            }

            // Verify value matches quantity * unit cost
            $expectedValue = $ledgerIntent->quantityDelta->mul($ledgerIntent->unitCost);
            if ($ledgerIntent->valueDelta->compareTo($expectedValue) !== 0) {
                throw new InvalidArgumentException('Positive adjustment value delta does not match quantity * unit cost.');
            }

            $quantityAfter = $intent->currentQuantity->add($ledgerIntent->quantityDelta);
            $carryingValueAfter = $intent->currentCarryingValue->add($ledgerIntent->valueDelta);

            if ($quantityAfter->isZero()) {
                $waucAfter = null;
            } else {
                $waucAfter = $carryingValueAfter->div($quantityAfter);
            }
        } else {
            // Negative adjustment (AdjustmentOut)
            if ($intent->currentQuantity->isZero() || $intent->currentQuantity->isNegative()) {
                throw new InvalidArgumentException('Cannot adjust out from zero or negative stock.');
            }

            $adjQty = $ledgerIntent->quantityDelta->abs();
            if ($intent->currentQuantity->compareTo($adjQty) < 0) {
                throw new InvalidArgumentException('Adjustment quantity exceeds available quantity.');
            }

            // Calculate current WAUC
            $currentWauc = $intent->currentCarryingValue->div($intent->currentQuantity);

            if ($ledgerIntent->unitCost->compareTo($currentWauc) !== 0) {
                throw new InvalidArgumentException('Negative adjustment unit cost must match prevailing carrying cost.');
            }

            if ($intent->currentQuantity->compareTo($adjQty) === 0) {
                // Zero-balance adjustment
                $relievedValue = $intent->currentCarryingValue;
                $expectedValueDelta = AvcoDecimal::zero()->sub($relievedValue);

                if ($ledgerIntent->valueDelta->compareTo($expectedValueDelta) !== 0) {
                    throw new InvalidArgumentException('Zero-balance negative adjustment value delta must relieve entire carrying value.');
                }

                $quantityAfter = AvcoDecimal::zero();
                $carryingValueAfter = AvcoDecimal::zero();
                $waucAfter = null;
            } else {
                // Partial negative adjustment
                $relievedValue = $adjQty->mul($currentWauc);
                $expectedValueDelta = AvcoDecimal::zero()->sub($relievedValue);

                if ($ledgerIntent->valueDelta->compareTo($expectedValueDelta) !== 0) {
                    throw new InvalidArgumentException('Negative adjustment value delta does not match prevailing carrying cost.');
                }

                $quantityAfter = $intent->currentQuantity->add($ledgerIntent->quantityDelta);
                $carryingValueAfter = $intent->currentCarryingValue->add($ledgerIntent->valueDelta);

                if ($quantityAfter->isNegative()) {
                    throw new InvalidArgumentException('Resulting quantity cannot be negative.');
                }
                if ($carryingValueAfter->isNegative()) {
                    throw new InvalidArgumentException('Resulting carrying value cannot be negative.');
                }

                $waucAfter = $currentWauc;
            }
        }

        return new ControlledAdjustmentValuationPlan(
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

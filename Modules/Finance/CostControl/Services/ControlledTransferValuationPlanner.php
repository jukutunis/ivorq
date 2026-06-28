<?php

namespace Modules\Finance\CostControl\Services;

use InvalidArgumentException;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

/**
 * Pure paired transfer valuation planner.
 */
final class ControlledTransferValuationPlanner
{
    /**
     * Plan a paired transfer state transition.
     *
     * @param ControlledTransferValuationIntent $intent
     * @return ControlledTransferValuationPlan
     * @throws InvalidArgumentException
     */
    public function plan(
        ControlledTransferValuationIntent $intent
    ): ControlledTransferValuationPlan {
        $outbound = $intent->outboundIntent;
        $inbound = $intent->inboundIntent;

        // 1. Validate entry types
        if ($outbound->entryType !== 'transfer' || !$outbound->quantityDelta->isNegative()) {
            throw new InvalidArgumentException('Outbound intent must be of entry type transfer with a negative quantity.');
        }

        if ($inbound->entryType !== 'transfer' || !$inbound->quantityDelta->isPositive()) {
            throw new InvalidArgumentException('Inbound intent must be of entry type transfer with a positive quantity.');
        }

        // 2. Property and item match exactly across both legs
        if ($outbound->propertyId !== $intent->propertyId || $inbound->propertyId !== $intent->propertyId) {
            throw new InvalidArgumentException('Property ID mismatch between intents and intent scope.');
        }

        // 3. Source and destination locations differ
        if ($intent->sourceLocationId === $intent->destinationLocationId) {
            throw new InvalidArgumentException('Source and destination locations cannot be the same.');
        }

        // 4. Match outbound and inbound quantities, values, and unit costs
        if ($outbound->quantityDelta->abs()->compareTo($inbound->quantityDelta) !== 0) {
            throw new InvalidArgumentException('Outbound absolute quantity must match inbound quantity.');
        }

        if ($outbound->valueDelta->abs()->compareTo($inbound->valueDelta) !== 0) {
            throw new InvalidArgumentException('Outbound absolute value must match inbound value.');
        }

        if ($outbound->unitCost->compareTo($inbound->unitCost) !== 0) {
            throw new InvalidArgumentException('Outbound and inbound unit costs must match.');
        }

        if (!$outbound->unitCost->isPositive()) {
            throw new InvalidArgumentException('Transfer unit cost must be positive.');
        }

        // 5. Source current state validation
        if ($intent->sourceCurrentQuantity->isZero() || $intent->sourceCurrentQuantity->isNegative()) {
            throw new InvalidArgumentException('Cannot transfer from zero or negative source stock.');
        }

        // 6. Calculate prevailing source WAUC and verify it matches the transfer unit cost
        $currentSourceWauc = $intent->sourceCurrentCarryingValue->div($intent->sourceCurrentQuantity);

        if ($outbound->unitCost->compareTo($currentSourceWauc) !== 0) {
            throw new InvalidArgumentException('Transfer unit cost must match current source WAUC.');
        }

        // 7. Verify outbound value matches transfer quantity * current source WAUC exactly
        $transferQty = $outbound->quantityDelta->abs();
        $expectedOutboundValue = $transferQty->mul($currentSourceWauc);
        if ($outbound->valueDelta->abs()->compareTo($expectedOutboundValue) !== 0) {
            throw new InvalidArgumentException('Outbound value delta must match transfer quantity multiplied by source WAUC.');
        }

        // 8. Verify source has enough quantity and carrying value
        if ($intent->sourceCurrentQuantity->compareTo($transferQty) < 0) {
            throw new InvalidArgumentException('Insufficient source quantity.');
        }

        if ($intent->sourceCurrentCarryingValue->compareTo($outbound->valueDelta->abs()) < 0) {
            throw new InvalidArgumentException('Insufficient source carrying value.');
        }

        // 9. Source sequence progression validation
        $sourceNextSequence = new ValuationSequence(
            propertyId: $intent->propertyId,
            itemId: $intent->itemId,
            valuationScope: $intent->sourceValuationScope,
            businessDate: $outbound->businessDate,
            ledgerSequence: $outbound->entrySequence
        );

        if ($intent->sourceCurrentLastValuationSequence === null) {
            if ($outbound->entrySequence !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Source sequence gap. First sequence must be 1, got %d.', $outbound->entrySequence)
                );
            }
        } else {
            $lastSeq = $intent->sourceCurrentLastValuationSequence;
            if ($outbound->entrySequence <= $lastSeq->ledgerSequence) {
                throw new InvalidArgumentException('Stale or duplicate source sequence.');
            }
            if ($outbound->entrySequence !== $lastSeq->ledgerSequence + 1) {
                throw new InvalidArgumentException(
                    sprintf('Source sequence gap. Expected %d, got %d.', $lastSeq->ledgerSequence + 1, $outbound->entrySequence)
                );
            }
            if ($sourceNextSequence->compareTo($lastSeq) <= 0) {
                throw new InvalidArgumentException('Source sequence out of order chronologically.');
            }
        }

        // 10. Destination sequence progression validation
        $destNextSequence = new ValuationSequence(
            propertyId: $intent->propertyId,
            itemId: $intent->itemId,
            valuationScope: $intent->destinationValuationScope,
            businessDate: $inbound->businessDate,
            ledgerSequence: $inbound->entrySequence
        );

        if ($intent->destinationCurrentLastValuationSequence === null) {
            if ($inbound->entrySequence !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Destination sequence gap. First sequence must be 1, got %d.', $inbound->entrySequence)
                );
            }
        } else {
            $lastSeq = $intent->destinationCurrentLastValuationSequence;
            if ($inbound->entrySequence <= $lastSeq->ledgerSequence) {
                throw new InvalidArgumentException('Stale or duplicate destination sequence.');
            }
            if ($inbound->entrySequence !== $lastSeq->ledgerSequence + 1) {
                throw new InvalidArgumentException(
                    sprintf('Destination sequence gap. Expected %d, got %d.', $lastSeq->ledgerSequence + 1, $inbound->entrySequence)
                );
            }
            if ($destNextSequence->compareTo($lastSeq) <= 0) {
                throw new InvalidArgumentException('Destination sequence out of order chronologically.');
            }
        }

        // 11. Perform source calculations
        $sourceQuantityAfter = $intent->sourceCurrentQuantity->sub($transferQty);
        $sourceCarryingValueAfter = $intent->sourceCurrentCarryingValue->sub($outbound->valueDelta->abs());

        if ($sourceQuantityAfter->isZero()) {
            $sourceCarryingValueAfter = AvcoDecimal::zero();
            $sourceWaucAfter = null;
        } else {
            if ($sourceQuantityAfter->isNegative()) {
                throw new InvalidArgumentException('Resulting source quantity cannot be negative.');
            }
            if ($sourceCarryingValueAfter->isNegative()) {
                throw new InvalidArgumentException('Resulting source carrying value cannot be negative.');
            }
            $sourceWaucAfter = $currentSourceWauc;
        }

        // 12. Perform destination calculations
        $destQuantityAfter = $intent->destinationCurrentQuantity->add($inbound->quantityDelta);
        $destCarryingValueAfter = $intent->destinationCurrentCarryingValue->add($inbound->valueDelta);

        if ($destQuantityAfter->isNegative()) {
            throw new InvalidArgumentException('Resulting destination quantity cannot be negative.');
        }
        if ($destCarryingValueAfter->isNegative()) {
            throw new InvalidArgumentException('Resulting destination carrying value cannot be negative.');
        }

        $destWaucAfter = $destCarryingValueAfter->div($destQuantityAfter);

        // 13. Construct and return plan
        return new ControlledTransferValuationPlan(
            sourceValuationScope: $intent->sourceValuationScope,
            destinationValuationScope: $intent->destinationValuationScope,
            sourceQuantityBefore: $intent->sourceCurrentQuantity,
            sourceQuantityAfter: $sourceQuantityAfter,
            sourceCarryingValueBefore: $intent->sourceCurrentCarryingValue,
            sourceCarryingValueAfter: $sourceCarryingValueAfter,
            sourceWeightedAverageUnitCostAfter: $sourceWaucAfter,
            sourceLastAppliedValuationSequenceBefore: $intent->sourceCurrentLastValuationSequence,
            sourceLastAppliedValuationSequenceAfter: $sourceNextSequence,
            destinationQuantityBefore: $intent->destinationCurrentQuantity,
            destinationQuantityAfter: $destQuantityAfter,
            destinationCarryingValueBefore: $intent->destinationCurrentCarryingValue,
            destinationCarryingValueAfter: $destCarryingValueAfter,
            destinationWeightedAverageUnitCostAfter: $destWaucAfter,
            destinationLastAppliedValuationSequenceBefore: $intent->destinationCurrentLastValuationSequence,
            destinationLastAppliedValuationSequenceAfter: $destNextSequence,
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );
    }
}

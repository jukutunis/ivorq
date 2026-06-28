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

        // 1. Entry type validation
        if ($ledgerIntent->entryType !== 'receipt' && $ledgerIntent->entryType !== 'issue') {
            throw new InvalidArgumentException(
                sprintf('Unsupported valuation movement type "%s". Only "receipt" and "issue" are supported.', $ledgerIntent->entryType)
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

        if ($ledgerIntent->entryType === 'receipt') {
            // Receipt quantity and value validation
            if (!$ledgerIntent->quantityDelta->isPositive()) {
                throw new InvalidArgumentException('Receipt quantity delta must be positive.');
            }

            if ($ledgerIntent->valueDelta->isNegative()) {
                throw new InvalidArgumentException('Receipt value delta cannot be negative.');
            }

            // Resulting quantity validation
            $quantityAfter = $intent->currentQuantity->add($ledgerIntent->quantityDelta);
            if (!$quantityAfter->isPositive()) {
                throw new InvalidArgumentException('Resulting quantity must be positive.');
            }

            // AVCO receipt calculation
            $carryingValueAfter = $intent->currentCarryingValue->add($ledgerIntent->valueDelta);
            $waucAfter = $carryingValueAfter->div($quantityAfter);
        } else {
            // Issue validation
            if (!$ledgerIntent->quantityDelta->isNegative()) {
                throw new InvalidArgumentException('Issue quantity delta must be negative.');
            }

            if ($ledgerIntent->quantityDelta->isZero()) {
                throw new InvalidArgumentException('Issue quantity delta cannot be zero.');
            }

            if (!$ledgerIntent->valueDelta->isNegative()) {
                throw new InvalidArgumentException('Issue value delta must be negative.');
            }

            if (!$ledgerIntent->unitCost->isPositive()) {
                throw new InvalidArgumentException('Issue unit cost must be positive.');
            }

            if ($intent->currentQuantity->isZero() || $intent->currentQuantity->isNegative()) {
                throw new InvalidArgumentException('Cannot issue when current quantity is zero or negative.');
            }

            $issueQty = $ledgerIntent->quantityDelta->abs();

            if ($intent->currentQuantity->compareTo($issueQty) < 0) {
                throw new InvalidArgumentException('Issue quantity exceeds available quantity.');
            }

            // Calculate current WAUC
            $currentWauc = $intent->currentCarryingValue->div($intent->currentQuantity);

            if ($ledgerIntent->unitCost->compareTo($currentWauc) !== 0) {
                throw new InvalidArgumentException('Issue unit cost does not match prevailing carrying cost.');
            }

            if ($intent->currentQuantity->compareTo($issueQty) === 0) {
                // Zero-balance issue
                $relievedValue = $intent->currentCarryingValue;
                $expectedValueDelta = AvcoDecimal::zero()->sub($relievedValue);

                if ($ledgerIntent->valueDelta->compareTo($expectedValueDelta) !== 0) {
                    throw new InvalidArgumentException('Issue value delta does not match prevailing carrying cost.');
                }

                $quantityAfter = AvcoDecimal::zero();
                $carryingValueAfter = AvcoDecimal::zero();
                $waucAfter = null;
            } else {
                // Partial issue
                $relievedValue = $issueQty->mul($currentWauc);
                $expectedValueDelta = AvcoDecimal::zero()->sub($relievedValue);

                if ($ledgerIntent->valueDelta->compareTo($expectedValueDelta) !== 0) {
                    throw new InvalidArgumentException('Issue value delta does not match prevailing carrying cost.');
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

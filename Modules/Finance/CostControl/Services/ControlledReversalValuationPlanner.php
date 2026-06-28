<?php

namespace Modules\Finance\CostControl\Services;

use InvalidArgumentException;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledReversalValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

class ControlledReversalValuationPlanner
{
    public function plan(
        ControlledValuationStateTransitionIntent $intent
    ): ControlledReversalValuationPlan {
        $ledgerIntent = $intent->costLedgerIntent;

        if ($ledgerIntent->entryType !== 'reversal') {
            throw new InvalidArgumentException(
                sprintf('Unsupported movement type "%s". Only "reversal" is supported.', $ledgerIntent->entryType)
            );
        }

        if (trim($ledgerIntent->sourceInventoryTransactionId) === '') {
            throw new InvalidArgumentException('Source inventory transaction reference cannot be blank.');
        }

        if (trim($ledgerIntent->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key cannot be blank.');
        }

        $nextSequence = new ValuationSequence(
            propertyId: $intent->propertyId,
            itemId: $intent->itemId,
            valuationScope: $intent->valuationScope,
            businessDate: $ledgerIntent->businessDate,
            ledgerSequence: $ledgerIntent->entrySequence
        );

        if ($intent->currentLastAppliedValuationSequence === null) {
            if ($ledgerIntent->entrySequence !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Sequence gap detected. First sequence must be 1, got %d.', $ledgerIntent->entrySequence)
                );
            }
        } else {
            $lastSeq = $intent->currentLastAppliedValuationSequence;

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

        $quantityAfter = $intent->currentQuantity->add($ledgerIntent->quantityDelta);
        if ($quantityAfter->isNegative()) {
            throw new InvalidArgumentException('Resulting quantity cannot be negative.');
        }

        $carryingValueAfter = $intent->currentCarryingValue->add($ledgerIntent->valueDelta);
        if ($carryingValueAfter->isNegative()) {
            throw new InvalidArgumentException('Resulting carrying value cannot be negative.');
        }

        if ($quantityAfter->isZero()) {
            $waucAfter = null;
        } else {
            $waucAfter = $carryingValueAfter->div($quantityAfter);
        }

        return new ControlledReversalValuationPlan(
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

<?php

namespace Modules\Finance\CostControl\ValueObjects;

/**
 * final readonly value object carrying the resulting transition plan for a paired transfer.
 */
final class ControlledTransferValuationPlan
{
    public readonly string $sourceValuationScope;
    public readonly string $destinationValuationScope;

    public readonly AvcoDecimal $sourceQuantityBefore;
    public readonly AvcoDecimal $sourceQuantityAfter;
    public readonly AvcoDecimal $sourceCarryingValueBefore;
    public readonly AvcoDecimal $sourceCarryingValueAfter;
    public readonly ?AvcoDecimal $sourceWeightedAverageUnitCostAfter;
    public readonly ?ValuationSequence $sourceLastAppliedValuationSequenceBefore;
    public readonly ValuationSequence $sourceLastAppliedValuationSequenceAfter;

    public readonly AvcoDecimal $destinationQuantityBefore;
    public readonly AvcoDecimal $destinationQuantityAfter;
    public readonly AvcoDecimal $destinationCarryingValueBefore;
    public readonly AvcoDecimal $destinationCarryingValueAfter;
    public readonly ?AvcoDecimal $destinationWeightedAverageUnitCostAfter;
    public readonly ?ValuationSequence $destinationLastAppliedValuationSequenceBefore;
    public readonly ValuationSequence $destinationLastAppliedValuationSequenceAfter;

    public readonly ControlledValuationCostLedgerIntent $outboundIntent;
    public readonly ControlledValuationCostLedgerIntent $inboundIntent;

    public function __construct(
        string $sourceValuationScope,
        string $destinationValuationScope,
        AvcoDecimal $sourceQuantityBefore,
        AvcoDecimal $sourceQuantityAfter,
        AvcoDecimal $sourceCarryingValueBefore,
        AvcoDecimal $sourceCarryingValueAfter,
        ?AvcoDecimal $sourceWeightedAverageUnitCostAfter,
        ?ValuationSequence $sourceLastAppliedValuationSequenceBefore,
        ValuationSequence $sourceLastAppliedValuationSequenceAfter,
        AvcoDecimal $destinationQuantityBefore,
        AvcoDecimal $destinationQuantityAfter,
        AvcoDecimal $destinationCarryingValueBefore,
        AvcoDecimal $destinationCarryingValueAfter,
        ?AvcoDecimal $destinationWeightedAverageUnitCostAfter,
        ?ValuationSequence $destinationLastAppliedValuationSequenceBefore,
        ValuationSequence $destinationLastAppliedValuationSequenceAfter,
        ControlledValuationCostLedgerIntent $outboundIntent,
        ControlledValuationCostLedgerIntent $inboundIntent
    ) {
        $this->sourceValuationScope = $sourceValuationScope;
        $this->destinationValuationScope = $destinationValuationScope;
        $this->sourceQuantityBefore = $sourceQuantityBefore;
        $this->sourceQuantityAfter = $sourceQuantityAfter;
        $this->sourceCarryingValueBefore = $sourceCarryingValueBefore;
        $this->sourceCarryingValueAfter = $sourceCarryingValueAfter;
        $this->sourceWeightedAverageUnitCostAfter = $sourceWeightedAverageUnitCostAfter;
        $this->sourceLastAppliedValuationSequenceBefore = $sourceLastAppliedValuationSequenceBefore;
        $this->sourceLastAppliedValuationSequenceAfter = $sourceLastAppliedValuationSequenceAfter;
        $this->destinationQuantityBefore = $destinationQuantityBefore;
        $this->destinationQuantityAfter = $destinationQuantityAfter;
        $this->destinationCarryingValueBefore = $destinationCarryingValueBefore;
        $this->destinationCarryingValueAfter = $destinationCarryingValueAfter;
        $this->destinationWeightedAverageUnitCostAfter = $destinationWeightedAverageUnitCostAfter;
        $this->destinationLastAppliedValuationSequenceBefore = $destinationLastAppliedValuationSequenceBefore;
        $this->destinationLastAppliedValuationSequenceAfter = $destinationLastAppliedValuationSequenceAfter;
        $this->outboundIntent = $outboundIntent;
        $this->inboundIntent = $inboundIntent;
    }
}

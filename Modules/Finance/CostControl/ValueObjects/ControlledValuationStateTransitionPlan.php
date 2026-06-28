<?php

namespace Modules\Finance\CostControl\ValueObjects;

/**
 * final readonly value object containing the output of a pure controlled
 * receipt valuation plan.
 */
final readonly class ControlledValuationStateTransitionPlan
{
    public string $valuationScope;
    public AvcoDecimal $quantityBefore;
    public AvcoDecimal $quantityAfter;
    public AvcoDecimal $carryingValueBefore;
    public AvcoDecimal $carryingValueAfter;
    public ?AvcoDecimal $weightedAverageUnitCostAfter;
    public ?ValuationSequence $lastAppliedValuationSequenceBefore;
    public ValuationSequence $lastAppliedValuationSequenceAfter;
    public ControlledValuationCostLedgerIntent $costLedgerIntent;

    public function __construct(
        string $valuationScope,
        AvcoDecimal $quantityBefore,
        AvcoDecimal $quantityAfter,
        AvcoDecimal $carryingValueBefore,
        AvcoDecimal $carryingValueAfter,
        ?AvcoDecimal $weightedAverageUnitCostAfter,
        ?ValuationSequence $lastAppliedValuationSequenceBefore,
        ValuationSequence $lastAppliedValuationSequenceAfter,
        ControlledValuationCostLedgerIntent $costLedgerIntent
    ) {
        $this->valuationScope = $valuationScope;
        $this->quantityBefore = $quantityBefore;
        $this->quantityAfter = $quantityAfter;
        $this->carryingValueBefore = $carryingValueBefore;
        $this->carryingValueAfter = $carryingValueAfter;
        $this->weightedAverageUnitCostAfter = $weightedAverageUnitCostAfter;
        $this->lastAppliedValuationSequenceBefore = $lastAppliedValuationSequenceBefore;
        $this->lastAppliedValuationSequenceAfter = $lastAppliedValuationSequenceAfter;
        $this->costLedgerIntent = $costLedgerIntent;
    }
}

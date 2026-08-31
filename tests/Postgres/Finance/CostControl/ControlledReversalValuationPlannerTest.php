<?php

namespace Tests\Postgres\Finance\CostControl;

use Modules\Finance\CostControl\Services\ControlledReversalValuationPlanner;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Tests\PostgresTestCase;

class ControlledReversalValuationPlannerTest extends PostgresTestCase
{
    private ControlledReversalValuationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new ControlledReversalValuationPlanner;
    }

    public function test_planner_correctly_negates_and_computes_reversal(): void
    {
        $ledgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: 'prop1',
            sourceInventoryTransactionId: 'tx-rev',
            priorCostLedgerEntryId: null,
            entryType: 'reversal',
            idempotencyKey: 'idem1',
            entrySequence: 2,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('-5.0000'),
            unitCost: new AvcoDecimal('10.0000'),
            valueDelta: new AvcoDecimal('-50.0000'),
            businessDate: '2026-06-29',
            occurredAt: '2026-06-29 10:00:00'
        );

        $priorSeq = new ValuationSequence('prop1', 'itm1', 'property:prop1:location:loc1:item:itm1', '2026-06-29', 1);

        $intent = new ControlledValuationStateTransitionIntent(
            propertyId: 'prop1',
            locationId: 'loc1',
            itemId: 'itm1',
            currentLastAppliedValuationSequence: $priorSeq,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $plan = $this->planner->plan($intent);

        $this->assertSame('5.0000', $plan->quantityAfter->getValue());
        $this->assertSame('50.0000', $plan->carryingValueAfter->getValue());
        $this->assertSame('10.0000', $plan->weightedAverageUnitCostAfter?->getValue());
        $this->assertEquals(2, $plan->lastAppliedValuationSequenceAfter->ledgerSequence);
    }

    public function test_planner_sequence_gap_fails(): void
    {
        $ledgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: 'prop1',
            sourceInventoryTransactionId: 'tx-rev',
            priorCostLedgerEntryId: null,
            entryType: 'reversal',
            idempotencyKey: 'idem1',
            entrySequence: 3,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal('-5.0000'),
            unitCost: new AvcoDecimal('10.0000'),
            valueDelta: new AvcoDecimal('-50.0000'),
            businessDate: '2026-06-29',
            occurredAt: '2026-06-29 10:00:00'
        );

        $priorSeq = new ValuationSequence('prop1', 'itm1', 'property:prop1:location:loc1:item:itm1', '2026-06-29', 1);

        $intent = new ControlledValuationStateTransitionIntent(
            propertyId: 'prop1',
            locationId: 'loc1',
            itemId: 'itm1',
            currentLastAppliedValuationSequence: $priorSeq,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sequence gap detected.');

        $this->planner->plan($intent);
    }
}

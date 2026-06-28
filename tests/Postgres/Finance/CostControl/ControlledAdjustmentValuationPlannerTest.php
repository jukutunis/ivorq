<?php

namespace Tests\Postgres\Finance\CostControl;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Services\ControlledAdjustmentValuationPlanner;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

class ControlledAdjustmentValuationPlannerTest extends PostgresTestCase
{
    use RefreshDatabase;

    private ControlledAdjustmentValuationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new ControlledAdjustmentValuationPlanner();
    }

    private function makeLedgerIntent(
        string $entryType = 'adjustment',
        int $entrySequence = 1,
        string $quantityDelta = '5.0000',
        string $unitCost = '10.0000',
        string $valueDelta = '50.0000',
        string $businessDate = '2026-06-28'
    ): ControlledValuationCostLedgerIntent {
        return new ControlledValuationCostLedgerIntent(
            propertyId: 'prop_1',
            sourceInventoryTransactionId: 'tx_1',
            priorCostLedgerEntryId: null,
            entryType: $entryType,
            idempotencyKey: 'idem_1',
            entrySequence: $entrySequence,
            currencyCode: 'USD',
            quantityDelta: new AvcoDecimal($quantityDelta),
            unitCost: new AvcoDecimal($unitCost),
            valueDelta: new AvcoDecimal($valueDelta),
            businessDate: $businessDate,
            occurredAt: '2026-06-28 12:00:00'
        );
    }

    /**
     * 1. A valid adjustment produces exact quantity, carrying value, WAUC, and sequence.
     */
    public function test_valid_positive_adjustment_produces_correct_plan(): void
    {
        $ledgerIntent = $this->makeLedgerIntent(
            quantityDelta: '5.0000',
            unitCost: '12.0000',
            valueDelta: '60.0000'
        );

        $intent = new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $plan = $this->planner->plan($intent);

        $this->assertInstanceOf(ControlledAdjustmentValuationPlan::class, $plan);
        $this->assertEquals('15.0000', $plan->quantityAfter->getValue());
        $this->assertEquals('160.0000', $plan->carryingValueAfter->getValue());
        $this->assertEquals('10.6667', $plan->weightedAverageUnitCostAfter->getValue());
        $this->assertEquals(1, $plan->lastAppliedValuationSequenceAfter->ledgerSequence);
        $this->assertSame($ledgerIntent, $plan->costLedgerIntent);
    }

    /**
     * 2. Negative adjustment uses prevailing WAUC as valuation authority.
     */
    public function test_valid_negative_adjustment_uses_prevailing_wauc(): void
    {
        $lastSeq = new ValuationSequence(
            propertyId: 'prop_1',
            itemId: 'item_1',
            valuationScope: 'property:prop_1:location:loc_1:item:item_1',
            businessDate: '2026-06-27',
            ledgerSequence: 5
        );

        // Prevailing WAUC is 10.0000 (100.0000 / 10.0000)
        // Adjusting out 3 units. Qty change = -3.0000, value change must be -30.0000
        $ledgerIntent = $this->makeLedgerIntent(
            entrySequence: 6,
            quantityDelta: '-3.0000',
            unitCost: '10.0000',
            valueDelta: '-30.0000'
        );

        $intent = new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: $lastSeq,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $plan = $this->planner->plan($intent);

        $this->assertEquals('7.0000', $plan->quantityAfter->getValue());
        $this->assertEquals('70.0000', $plan->carryingValueAfter->getValue());
        $this->assertEquals('10.0000', $plan->weightedAverageUnitCostAfter->getValue());
    }

    /**
     * 3. Zero-balance negative adjustment sets WAUC to null.
     */
    public function test_negative_adjustment_reaches_zero_balance(): void
    {
        $lastSeq = new ValuationSequence(
            propertyId: 'prop_1',
            itemId: 'item_1',
            valuationScope: 'property:prop_1:location:loc_1:item:item_1',
            businessDate: '2026-06-27',
            ledgerSequence: 5
        );

        $ledgerIntent = $this->makeLedgerIntent(
            entrySequence: 6,
            quantityDelta: '-10.0000',
            unitCost: '10.0000',
            valueDelta: '-100.0000'
        );

        $intent = new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: $lastSeq,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $plan = $this->planner->plan($intent);

        $this->assertEquals('0.0000', $plan->quantityAfter->getValue());
        $this->assertEquals('0.0000', $plan->carryingValueAfter->getValue());
        $this->assertNull($plan->weightedAverageUnitCostAfter);
    }

    /**
     * 4. Insufficient quantity fails on negative adjustment.
     */
    public function test_negative_adjustment_exceeds_available_quantity(): void
    {
        $ledgerIntent = $this->makeLedgerIntent(
            quantityDelta: '-15.0000',
            unitCost: '10.0000',
            valueDelta: '-150.0000'
        );

        $intent = new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $this->expectException(InvalidArgumentException::class);
        $this->planner->plan($intent);
    }

    /**
     * 5. Mismatched property ID throws.
     */
    public function test_mismatched_property_fails(): void
    {
        $ledgerIntent = $this->makeLedgerIntent();

        $this->expectException(InvalidArgumentException::class);
        new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_different',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );
    }

    /**
     * 6. Unsupported movement type throws.
     */
    public function test_unsupported_movement_type_fails(): void
    {
        $ledgerIntent = $this->makeLedgerIntent(entryType: 'receipt');

        $this->expectException(InvalidArgumentException::class);
        new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );
    }

    /**
     * 7. Sequence gap or chronologically invalid sequence throws.
     */
    public function test_sequence_gap_fails(): void
    {
        $ledgerIntent = $this->makeLedgerIntent(entrySequence: 3);

        $intent = new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $this->expectException(InvalidArgumentException::class);
        $this->planner->plan($intent);
    }

    /**
     * 8. Database records remain unmutated by plan().
     */
    public function test_plan_does_not_mutate_database(): void
    {
        $tables = [
            'cost_avco_states',
            'cost_authority_enrollments',
            'inventory_transactions',
            'cost_ledger_entries'
        ];

        $countsBefore = [];
        foreach ($tables as $table) {
            try {
                $countsBefore[$table] = DB::table($table)->count();
            } catch (\Throwable $e) {
                $countsBefore[$table] = null;
            }
        }

        $ledgerIntent = $this->makeLedgerIntent();
        $intent = new ControlledAdjustmentValuationIntent(
            propertyId: 'prop_1',
            locationId: 'loc_1',
            itemId: 'item_1',
            currentLastAppliedValuationSequence: null,
            currentQuantity: new AvcoDecimal('10.0000'),
            currentCarryingValue: new AvcoDecimal('100.0000'),
            costLedgerIntent: $ledgerIntent
        );

        $this->planner->plan($intent);

        foreach ($tables as $table) {
            if ($countsBefore[$table] !== null) {
                $this->assertEquals($countsBefore[$table], DB::table($table)->count(), "Table '{$table}' was mutated!");
            }
        }
    }
}

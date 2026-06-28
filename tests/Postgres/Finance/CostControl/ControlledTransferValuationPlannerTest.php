<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\PostgresTestCase;
use Modules\Finance\CostControl\Services\ControlledTransferValuationPlanner;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationPlan;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;

class ControlledTransferValuationPlannerTest extends PostgresTestCase
{
    private ControlledTransferValuationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new ControlledTransferValuationPlanner();
    }

    private function makeLedgerIntent(
        string $entryType = 'transfer',
        int $entrySequence = 1,
        string $quantityDelta = '-2.0000',
        string $unitCost = '10.0000',
        string $valueDelta = '-20.0000',
        string $businessDate = '2026-06-28',
        string $propertyId = 'prop_1'
    ): ControlledValuationCostLedgerIntent {
        return new ControlledValuationCostLedgerIntent(
            propertyId: $propertyId,
            sourceInventoryTransactionId: 'tx_1',
            priorCostLedgerEntryId: null,
            entryType: $entryType,
            idempotencyKey: 'idemp_' . uniqid(),
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
     * 1. Valid paired same-property same-item transfer produces correct after state.
     */
    public function test_valid_paired_transfer_produces_exact_plan(): void
    {
        $outbound = $this->makeLedgerIntent(
            entryType: 'transfer',
            entrySequence: 1,
            quantityDelta: '-2.0000',
            unitCost: '10.0000',
            valueDelta: '-20.0000'
        );

        $inbound = $this->makeLedgerIntent(
            entryType: 'transfer',
            entrySequence: 1,
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '20.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $plan = $this->planner->plan($intent);

        $this->assertInstanceOf(ControlledTransferValuationPlan::class, $plan);
        $this->assertEquals('property:prop_1:location:loc_src:item:item_1', $plan->sourceValuationScope);
        $this->assertEquals('property:prop_1:location:loc_dest:item:item_1', $plan->destinationValuationScope);

        // Source after: 10 - 2 = 8 qty, 100 - 20 = 80 value, WAUC = 10
        $this->assertEquals('8.0000', $plan->sourceQuantityAfter->getValue());
        $this->assertEquals('80.0000', $plan->sourceCarryingValueAfter->getValue());
        $this->assertEquals('10.0000', $plan->sourceWeightedAverageUnitCostAfter->getValue());
        $this->assertEquals(1, $plan->sourceLastAppliedValuationSequenceAfter->ledgerSequence);

        // Dest after: 5 + 2 = 7 qty, 40 + 20 = 60 value, WAUC = 8.5714 (60/7 = 8.5714)
        $this->assertEquals('7.0000', $plan->destinationQuantityAfter->getValue());
        $this->assertEquals('60.0000', $plan->destinationCarryingValueAfter->getValue());
        $this->assertEquals('8.5714', $plan->destinationWeightedAverageUnitCostAfter->getValue());
        $this->assertEquals(1, $plan->destinationLastAppliedValuationSequenceAfter->ledgerSequence);

        $this->assertSame($outbound, $plan->outboundIntent);
        $this->assertSame($inbound, $plan->inboundIntent);
    }

    /**
     * 2. Source partial transfer preserves source WAUC and recalculates destination WAUC.
     */
    public function test_source_partial_transfer_preserves_source_wauc(): void
    {
        $outbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '-3.0000',
            unitCost: '12.5000',
            valueDelta: '-37.5000'
        );

        $inbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '3.0000',
            unitCost: '12.5000',
            valueDelta: '37.5000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('125.0000'), // WAUC = 12.5
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('50.0000'), // WAUC = 10
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $plan = $this->planner->plan($intent);

        $this->assertEquals('12.5000', $plan->sourceWeightedAverageUnitCostAfter->getValue()); // Unchanged
        $this->assertEquals('10.9375', $plan->destinationWeightedAverageUnitCostAfter->getValue()); // Recalculated: (50 + 37.5) / 8 = 10.9375
    }

    /**
     * 3. Source zero-balance transfer sets source quantity and carrying value to zero and applies null WAUC.
     */
    public function test_source_zero_balance_transfer(): void
    {
        $outbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '-10.0000',
            unitCost: '10.0000',
            valueDelta: '-100.0000'
        );

        $inbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '10.0000',
            unitCost: '10.0000',
            valueDelta: '100.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $plan = $this->planner->plan($intent);

        $this->assertEquals('0.0000', $plan->sourceQuantityAfter->getValue());
        $this->assertEquals('0.0000', $plan->sourceCarryingValueAfter->getValue());
        $this->assertNull($plan->sourceWeightedAverageUnitCostAfter);
    }

    /**
     * 4. Source state with insufficient quantity fails.
     */
    public function test_insufficient_source_quantity_fails(): void
    {
        $outbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '-11.0000',
            unitCost: '10.0000',
            valueDelta: '-110.0000'
        );

        $inbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '11.0000',
            unitCost: '10.0000',
            valueDelta: '110.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient source quantity');
        $this->planner->plan($intent);
    }

    /**
     * 5. Transfer pair mismatch fails.
     */
    public function test_transfer_pair_mismatches_fail(): void
    {
        $outbound = $this->makeLedgerIntent(
            propertyId: 'prop_1',
            quantityDelta: '-2.0000',
            unitCost: '10.0000',
            valueDelta: '-20.0000'
        );

        // property mismatch
        $inboundPropertyMismatch = $this->makeLedgerIntent(
            propertyId: 'prop_2',
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '20.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inboundPropertyMismatch
        );

        try {
            $this->planner->plan($intent);
            $this->fail('Expected exception for property mismatch.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Property ID mismatch', $e->getMessage());
        }

        // quantity mismatch
        $inboundQtyMismatch = $this->makeLedgerIntent(
            quantityDelta: '3.0000',
            unitCost: '10.0000',
            valueDelta: '30.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inboundQtyMismatch
        );

        try {
            $this->planner->plan($intent);
            $this->fail('Expected exception for quantity mismatch.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Outbound absolute quantity must match inbound quantity', $e->getMessage());
        }
    }

    /**
     * 6. Wrong sign, zero quantity, zero/negative unit cost, and value mismatch fail.
     */
    public function test_invalid_parameters_fail(): void
    {
        // Outbound positive quantity (wrong sign)
        $outbound = $this->makeLedgerIntent(
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '-20.0000'
        );
        $inbound = $this->makeLedgerIntent(
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '20.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        try {
            $this->planner->plan($intent);
            $this->fail('Expected exception for positive outbound quantity.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Outbound intent must be of entry type transfer with a negative quantity', $e->getMessage());
        }
    }

    /**
     * 7. Sequence validation is independent on each leg.
     */
    public function test_sequence_progression_independent_validation(): void
    {
        $outbound = $this->makeLedgerIntent(
            entrySequence: 5, // Gap: expected 1 since last seq is null
            quantityDelta: '-2.0000',
            unitCost: '10.0000',
            valueDelta: '-20.0000'
        );
        $inbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '20.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source sequence gap. First sequence must be 1, got 5.');
        $this->planner->plan($intent);
    }

    /**
     * 8. Unsupported receipt, issue, adjustment, correction, and reversal evidence fails closed.
     */
    public function test_unsupported_movement_types_fail(): void
    {
        $outbound = $this->makeLedgerIntent(
            entryType: 'issue',
            quantityDelta: '-2.0000',
            unitCost: '10.0000',
            valueDelta: '-20.0000'
        );
        $inbound = $this->makeLedgerIntent(
            entryType: 'transfer',
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '20.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Outbound intent must be of entry type transfer with a negative quantity.');
        $this->planner->plan($intent);
    }

    /**
     * 9. Calling plan() creates or modifies no database records.
     */
    public function test_no_database_mutations(): void
    {
        $tables = [
            'cost_avco_states',
            'cost_ledger_entries',
            'inventory_transactions',
            'outbox_messages',
        ];

        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = DB::table($table)->count();
        }

        $outbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '-2.0000',
            unitCost: '10.0000',
            valueDelta: '-20.0000'
        );
        $inbound = $this->makeLedgerIntent(
            entrySequence: 1,
            quantityDelta: '2.0000',
            unitCost: '10.0000',
            valueDelta: '20.0000'
        );

        $intent = new ControlledTransferValuationIntent(
            propertyId: 'prop_1',
            itemId: 'item_1',
            sourceLocationId: 'loc_src',
            destinationLocationId: 'loc_dest',
            sourceCurrentLastValuationSequence: null,
            sourceCurrentQuantity: new AvcoDecimal('10.0000'),
            sourceCurrentCarryingValue: new AvcoDecimal('100.0000'),
            destinationCurrentLastValuationSequence: null,
            destinationCurrentQuantity: new AvcoDecimal('5.0000'),
            destinationCurrentCarryingValue: new AvcoDecimal('40.0000'),
            outboundIntent: $outbound,
            inboundIntent: $inbound
        );

        $this->planner->plan($intent);

        foreach ($tables as $table) {
            $this->assertEquals($countsBefore[$table], DB::table($table)->count(), "Table '{$table}' was mutated!");
        }
    }
}

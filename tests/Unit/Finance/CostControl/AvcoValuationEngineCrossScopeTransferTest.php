<?php

namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\Services\AvcoValuationEngine;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\TransferValuationContext;

class AvcoValuationEngineCrossScopeTransferTest extends TestCase
{
    private AvcoValuationEngine $engine;

    private const PROPERTY_ID = 'prop-001';
    private const ITEM_ID     = 'item-001';
    private const SOURCE_SCOPE = 'property:prop-001:location:LOC-A:item:item-001';
    private const DEST_SCOPE   = 'property:prop-001:location:LOC-B:item:item-001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AvcoValuationEngine();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeState(string $scope, string $qty, ?string $wauc, string $cv, int $seq = 1): AvcoValuationState
    {
        $sequence = $seq > 0
            ? new ValuationSequence(self::PROPERTY_ID, self::ITEM_ID, $scope, '2026-06-27', $seq)
            : null;

        return new AvcoValuationState(
            self::PROPERTY_ID, self::ITEM_ID, $scope,
            new AvcoDecimal($qty),
            $wauc !== null ? new AvcoDecimal($wauc) : null,
            new AvcoDecimal($cv),
            $sequence,
        );
    }

    private function makeInput(string $scope, string $qty, string $eventType, int $seq, TransferValuationContext $ctx): AvcoValuationInput
    {
        return new AvcoValuationInput(
            'tx-ref-' . $seq,
            new ValuationSequence(self::PROPERTY_ID, self::ITEM_ID, $scope, '2026-06-27', $seq),
            $eventType,
            new AvcoDecimal($qty),
            null,
            '2026-06-27 09:00:00',
            false,
            null,
            null,
            $ctx
        );
    }

    private function makeCrossCtx(string $frozenCost = '0.0000'): TransferValuationContext
    {
        return new TransferValuationContext(
            self::PROPERTY_ID, self::ITEM_ID, self::SOURCE_SCOPE,
            new AvcoDecimal($frozenCost),
            self::DEST_SCOPE
        );
    }

    // -------------------------------------------------------------------------
    // Case 1 — Same-scope neutral is preserved (existing behavior)
    // -------------------------------------------------------------------------

    public function test_same_scope_transfer_remains_neutral_with_destination_scope_set(): void
    {
        $sameScope = self::SOURCE_SCOPE;
        $ctx = new TransferValuationContext(
            self::PROPERTY_ID, self::ITEM_ID, $sameScope,
            new AvcoDecimal('10.0000'),
            $sameScope // src = dst → same-scope neutral
        );

        $priorState = $this->makeState($sameScope, '10.0000', '10.0000', '100.0000', 1);
        $input = $this->makeInput($sameScope, '3.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', $result->reasonCode);
        $this->assertEquals('0.0000', $result->signedCarryingValueDelta->getValue());
        $this->assertEquals('10.0000', $result->newState->onHandQuantity->getValue());
        $this->assertEquals('10.0000', $result->newState->weightedAverageUnitCost->getValue());
        $this->assertEquals('100.0000', $result->newState->carryingValue->getValue());
    }

    public function test_same_scope_neutral_still_works_without_destination_scope(): void
    {
        $ctx = new TransferValuationContext(
            self::PROPERTY_ID, self::ITEM_ID, self::SOURCE_SCOPE,
            new AvcoDecimal('12.0000')
            // no destinationValuationScope — legacy mode
        );

        $priorState = $this->makeState(self::SOURCE_SCOPE, '5.0000', '12.0000', '60.0000', 1);
        $input = $this->makeInput(self::SOURCE_SCOPE, '2.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', $result->reasonCode);
    }

    // -------------------------------------------------------------------------
    // Case 2 — Cross-scope TransferOut reduces source quantity and carrying value
    // -------------------------------------------------------------------------

    public function test_cross_scope_transfer_out_reduces_source_quantity_and_carrying_value(): void
    {
        // Source state: 10 units @ 20.0000 WAUC = 200.0000 carrying value
        $priorState = $this->makeState(self::SOURCE_SCOPE, '10.0000', '20.0000', '200.0000', 1);
        $ctx        = $this->makeCrossCtx('20.0000');
        $input      = $this->makeInput(self::SOURCE_SCOPE, '-4.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('CROSS_SCOPE_TRANSFER_OUT', $result->reasonCode);

        // Quantity: 10 - 4 = 6
        $this->assertEquals('6.0000', $result->newState->onHandQuantity->getValue());
        // WAUC unchanged for partial outflow
        $this->assertEquals('20.0000', $result->newState->weightedAverageUnitCost->getValue());
        // Carrying value: 200 - (4 * 20) = 200 - 80 = 120
        $this->assertEquals('120.0000', $result->newState->carryingValue->getValue());
        // Value delta: -(4 * 20) = -80
        $this->assertEquals('-80.0000', $result->signedCarryingValueDelta->getValue());
        // Source carrying unit cost is the prior WAUC
        $this->assertEquals('20.0000', $result->sourceCarryingUnitCost->getValue());
    }

    public function test_cross_scope_transfer_out_of_entire_inventory_zeroes_state(): void
    {
        // Source state: 5 units @ 15.0000 WAUC = 75.0000 carrying value
        $priorState = $this->makeState(self::SOURCE_SCOPE, '5.0000', '15.0000', '75.0000', 1);
        $ctx        = $this->makeCrossCtx('15.0000');
        $input      = $this->makeInput(self::SOURCE_SCOPE, '-5.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('CROSS_SCOPE_TRANSFER_OUT', $result->reasonCode);
        $this->assertEquals('0.0000', $result->newState->onHandQuantity->getValue());
        $this->assertNull($result->newState->weightedAverageUnitCost);
        $this->assertEquals('0.0000', $result->newState->carryingValue->getValue());
        $this->assertEquals('-75.0000', $result->signedCarryingValueDelta->getValue());
        $this->assertEquals('15.0000', $result->sourceCarryingUnitCost->getValue());
    }

    // -------------------------------------------------------------------------
    // Case 3 — Cross-scope TransferIn uses frozen source cost, not destination WAUC
    // -------------------------------------------------------------------------

    public function test_cross_scope_transfer_in_uses_frozen_source_cost(): void
    {
        // Destination state: 8 units @ 25.0000 WAUC = 200.0000 carrying value
        $priorState = $this->makeState(self::DEST_SCOPE, '8.0000', '25.0000', '200.0000', 1);
        $ctx        = $this->makeCrossCtx('20.0000'); // frozen source cost = 20.0000
        $input      = $this->makeInput(self::DEST_SCOPE, '4.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('CROSS_SCOPE_TRANSFER_IN', $result->reasonCode);

        // Quantity: 8 + 4 = 12
        $this->assertEquals('12.0000', $result->newState->onHandQuantity->getValue());
        // Carrying value: 200 + (4 * 20) = 200 + 80 = 280
        $this->assertEquals('280.0000', $result->newState->carryingValue->getValue());
        // WAUC: 280 / 12 = 23.3333
        $this->assertEquals('23.3333', $result->newState->weightedAverageUnitCost->getValue());
        // Value delta: +(4 * 20) = +80
        $this->assertEquals('80.0000', $result->signedCarryingValueDelta->getValue());
        // sourceCarryingUnitCost is the frozen source cost
        $this->assertEquals('20.0000', $result->sourceCarryingUnitCost->getValue());
    }

    public function test_cross_scope_transfer_in_to_empty_destination_uses_frozen_cost_as_wauc(): void
    {
        // Destination state: 0 units, no WAUC, 0 carrying value
        $priorState = $this->makeState(self::DEST_SCOPE, '0.0000', null, '0.0000', 0);
        $ctx        = $this->makeCrossCtx('18.5000');
        $input      = $this->makeInput(self::DEST_SCOPE, '6.0000', 'transfer', 1, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('CROSS_SCOPE_TRANSFER_IN', $result->reasonCode);
        $this->assertEquals('6.0000', $result->newState->onHandQuantity->getValue());
        $this->assertEquals('111.0000', $result->newState->carryingValue->getValue());
        $this->assertEquals('18.5000', $result->newState->weightedAverageUnitCost->getValue());
        $this->assertEquals('111.0000', $result->signedCarryingValueDelta->getValue());
    }

    // -------------------------------------------------------------------------
    // Case 4 — Source and destination values balance exactly (no internal gain/loss)
    // -------------------------------------------------------------------------

    public function test_source_and_destination_transfer_values_balance_exactly(): void
    {
        $qty          = '7.0000';
        $frozenWauc   = '22.5000';
        $expectedValue = '157.5000'; // 7 * 22.5

        // Source leg
        $srcState  = $this->makeState(self::SOURCE_SCOPE, '20.0000', $frozenWauc, '450.0000', 1);
        $ctx       = $this->makeCrossCtx($frozenWauc);
        $srcInput  = $this->makeInput(self::SOURCE_SCOPE, "-{$qty}", 'transfer', 2, $ctx);
        $srcResult = $this->engine->evaluate($srcInput, $srcState);

        // Destination leg
        $dstState  = $this->makeState(self::DEST_SCOPE, '3.0000', '30.0000', '90.0000', 1);
        $dstInput  = $this->makeInput(self::DEST_SCOPE, $qty, 'transfer', 2, $ctx);
        $dstResult = $this->engine->evaluate($dstInput, $dstState);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $srcResult->status);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $dstResult->status);

        // Source outflow must equal destination inflow in absolute value
        $outflowAbs = $srcResult->signedCarryingValueDelta->abs();
        $this->assertEquals(
            $outflowAbs->getValue(),
            $dstResult->signedCarryingValueDelta->getValue(),
            'Source outflow and destination inflow must be equal in magnitude'
        );

        $this->assertEquals('-' . $expectedValue, $srcResult->signedCarryingValueDelta->getValue());
        $this->assertEquals($expectedValue, $dstResult->signedCarryingValueDelta->getValue());
    }

    public function test_destination_does_not_use_its_own_prior_wauc_for_incoming_cost(): void
    {
        $destWauc   = '50.0000'; // high prior WAUC at destination
        $frozenCost = '10.0000'; // cheap source cost

        $priorState = $this->makeState(self::DEST_SCOPE, '2.0000', $destWauc, '100.0000', 1);
        $ctx        = $this->makeCrossCtx($frozenCost);
        $input      = $this->makeInput(self::DEST_SCOPE, '5.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        // Incoming value must use frozen source cost (10), NOT destination WAUC (50)
        $expectedInflow = '50.0000'; // 5 * 10
        $this->assertEquals($expectedInflow, $result->signedCarryingValueDelta->getValue());
        // Total carrying: 100 + 50 = 150; WAUC: 150 / 7 ≈ 21.4285
        $this->assertEquals('150.0000', $result->newState->carryingValue->getValue());
        $this->assertEquals('21.4285', $result->newState->weightedAverageUnitCost->getValue());
    }

    // -------------------------------------------------------------------------
    // Case 5 — Invalid cross-scope legs are rejected
    // -------------------------------------------------------------------------

    public function test_cross_scope_source_leg_with_positive_quantity_is_rejected(): void
    {
        $priorState = $this->makeState(self::SOURCE_SCOPE, '10.0000', '20.0000', '200.0000', 1);
        $ctx        = $this->makeCrossCtx('20.0000');
        $input      = $this->makeInput(self::SOURCE_SCOPE, '4.0000', 'transfer', 2, $ctx); // positive → wrong sign

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('INVALID_CROSS_SCOPE_TRANSFER_LEG', $result->reasonCode);
    }

    public function test_cross_scope_destination_leg_with_negative_quantity_is_rejected(): void
    {
        $priorState = $this->makeState(self::DEST_SCOPE, '10.0000', '20.0000', '200.0000', 1);
        $ctx        = $this->makeCrossCtx('20.0000');
        $input      = $this->makeInput(self::DEST_SCOPE, '-4.0000', 'transfer', 2, $ctx); // negative → wrong sign

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('INVALID_CROSS_SCOPE_TRANSFER_LEG', $result->reasonCode);
    }

    public function test_cross_scope_with_wrong_prior_state_scope_is_rejected(): void
    {
        $otherScope = 'property:prop-001:location:LOC-C:item:item-001';
        $priorState = $this->makeState($otherScope, '5.0000', '10.0000', '50.0000', 1);
        $ctx        = $this->makeCrossCtx('10.0000'); // src=LOC-A, dst=LOC-B

        // priorState is LOC-C — not source or destination
        $input = $this->makeInput($otherScope, '-2.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('INVALID_CROSS_SCOPE_TRANSFER_LEG', $result->reasonCode);
    }

    public function test_cross_scope_transfer_out_with_null_wauc_is_rejected(): void
    {
        // Source state has no inventory yet (WAUC is null)
        $priorState = $this->makeState(self::SOURCE_SCOPE, '0.0000', null, '0.0000', 0);
        $ctx        = $this->makeCrossCtx('0.0000');
        $input      = $this->makeInput(self::SOURCE_SCOPE, '-1.0000', 'transfer', 1, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('MISSING_PREVAILING_CARRYING_COST', $result->reasonCode);
    }

    public function test_cross_scope_transfer_out_exceeding_available_quantity_is_rejected(): void
    {
        // Only 3 units available, trying to transfer 5
        $priorState = $this->makeState(self::SOURCE_SCOPE, '3.0000', '10.0000', '30.0000', 1);
        $ctx        = $this->makeCrossCtx('10.0000');
        $input      = $this->makeInput(self::SOURCE_SCOPE, '-5.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('CROSS_SCOPE_TRANSFER_OUT_EXCEEDS_AVAILABLE_QUANTITY', $result->reasonCode);
    }

    public function test_cross_scope_with_wrong_property_id_is_rejected(): void
    {
        $ctx = new TransferValuationContext(
            'other-property', self::ITEM_ID, self::SOURCE_SCOPE,
            new AvcoDecimal('10.0000'),
            self::DEST_SCOPE
        );

        $priorState = $this->makeState(self::SOURCE_SCOPE, '5.0000', '10.0000', '50.0000', 1);
        $input      = $this->makeInput(self::SOURCE_SCOPE, '-2.0000', 'transfer', 2, $ctx);

        $result = $this->engine->evaluate($input, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('SCOPE_MISMATCH', $result->reasonCode);
    }

    // -------------------------------------------------------------------------
    // Case 6 — Strict sequence gap still applies to both legs
    // -------------------------------------------------------------------------

    public function test_sequence_gap_is_rejected_for_source_leg(): void
    {
        // Prior state has last sequence 3; input has sequence 5 (gap)
        $priorState = $this->makeState(self::SOURCE_SCOPE, '10.0000', '20.0000', '200.0000', 3);
        $ctx        = $this->makeCrossCtx('20.0000');

        $gapInput = new AvcoValuationInput(
            'tx-ref-gap',
            new ValuationSequence(self::PROPERTY_ID, self::ITEM_ID, self::SOURCE_SCOPE, '2026-06-27', 5),
            'transfer',
            new AvcoDecimal('-4.0000'),
            null,
            '2026-06-27 09:00:00',
            false,
            null, null,
            $ctx
        );

        $result = $this->engine->evaluate($gapInput, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('SEQUENCE_GAP_DETECTED', $result->reasonCode);
    }

    public function test_sequence_gap_is_rejected_for_destination_leg(): void
    {
        // Prior state has last sequence 2; input has sequence 4 (gap)
        $priorState = $this->makeState(self::DEST_SCOPE, '8.0000', '25.0000', '200.0000', 2);
        $ctx        = $this->makeCrossCtx('20.0000');

        $gapInput = new AvcoValuationInput(
            'tx-ref-gap',
            new ValuationSequence(self::PROPERTY_ID, self::ITEM_ID, self::DEST_SCOPE, '2026-06-27', 4),
            'transfer',
            new AvcoDecimal('4.0000'),
            null,
            '2026-06-27 09:00:00',
            false,
            null, null,
            $ctx
        );

        $result = $this->engine->evaluate($gapInput, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('SEQUENCE_GAP_DETECTED', $result->reasonCode);
    }

    public function test_duplicate_sequence_is_rejected_for_source_leg(): void
    {
        $priorState = $this->makeState(self::SOURCE_SCOPE, '10.0000', '20.0000', '200.0000', 3);
        $ctx        = $this->makeCrossCtx('20.0000');

        $dupInput = new AvcoValuationInput(
            'tx-ref-dup',
            new ValuationSequence(self::PROPERTY_ID, self::ITEM_ID, self::SOURCE_SCOPE, '2026-06-27', 3),
            'transfer',
            new AvcoDecimal('-2.0000'),
            null,
            '2026-06-27 09:00:00',
            false,
            null, null,
            $ctx
        );

        $result = $this->engine->evaluate($dupInput, $priorState);

        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('OUT_OF_ORDER_OR_DUPLICATE_SEQUENCE', $result->reasonCode);
    }
}

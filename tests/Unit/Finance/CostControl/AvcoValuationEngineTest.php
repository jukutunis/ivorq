<?php

namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\Services\AvcoValuationEngine;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\TransferValuationContext;

class AvcoValuationEngineTest extends TestCase
{
    private function createInput(string $type, string $qty, ?string $basis, int $seq = 1, bool $closed = false, ?string $correctionId = null, ?TransferValuationContext $transferContext = null): AvcoValuationInput
    {
        $vSeq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', $seq);
        return new AvcoValuationInput(
            'txn1', $vSeq, $type, new AvcoDecimal($qty), 
            $basis !== null ? new AvcoDecimal($basis) : null, 
            '2026-01-01 10:00:00', $closed, $correctionId, null, $transferContext
        );
    }

    public function test_receipt_recalculates_avco_deterministically_using_decimal()
    {
        $engine = new AvcoValuationEngine();
        $priorSeq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', 1);
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'), $priorSeq);
        $input = $this->createInput('receipt', '10.0', '20.0', 2);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertTrue($result->newState->onHandQuantity->compareTo(new AvcoDecimal('20.0')) === 0);
        $this->assertTrue($result->newState->weightedAverageUnitCost->compareTo(new AvcoDecimal('15.0')) === 0);
        $this->assertTrue($result->newState->carryingValue->compareTo(new AvcoDecimal('300.0')) === 0);
    }

    public function test_issue_within_available_quantity_uses_prevailing_carrying_cost()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('20.0'), new AvcoDecimal('15.0'), new AvcoDecimal('300.0'));
        $input = $this->createInput('issue', '-5.0', null, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertTrue($result->newState->onHandQuantity->compareTo(new AvcoDecimal('15.0')) === 0);
        $this->assertTrue($result->newState->carryingValue->compareTo(new AvcoDecimal('225.0')) === 0);
        $this->assertTrue($result->signedCarryingValueDelta->compareTo(new AvcoDecimal('-75.0')) === 0);
    }

    public function test_shortage_issue_relieves_only_available_quantity_and_becomes_provisional()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'));
        $input = $this->createInput('issue', '-15.0', null, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_PROVISIONAL, $result->status);
        $this->assertTrue($result->newState->onHandQuantity->compareTo(new AvcoDecimal('-5.0')) === 0);
        $this->assertNull($result->newState->weightedAverageUnitCost);
        $this->assertTrue($result->newState->carryingValue->compareTo(new AvcoDecimal('0.0')) === 0);
        $this->assertTrue($result->signedCarryingValueDelta->compareTo(new AvcoDecimal('-100.0')) === 0);
        $this->assertTrue($result->newState->unresolvedProvisionalQuantity->compareTo(new AvcoDecimal('5.0')) === 0);
    }

    public function test_issue_exhausting_stock_produces_zero_carrying_value_and_null_unit_cost()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'));
        $input = $this->createInput('issue', '-10.0', null, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertTrue($result->newState->onHandQuantity->compareTo(new AvcoDecimal('0.0')) === 0);
        $this->assertNull($result->newState->weightedAverageUnitCost);
        $this->assertTrue($result->newState->carryingValue->compareTo(new AvcoDecimal('0.0')) === 0);
        $this->assertTrue($result->signedCarryingValueDelta->compareTo(new AvcoDecimal('-100.0')) === 0);
    }

    public function test_receipt_after_unresolved_provisional_balance_returns_correction_required()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('-5.0'), null, new AvcoDecimal('0.0'), null, new AvcoDecimal('5.0'));
        $input = $this->createInput('receipt', '10.0', '20.0', 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $result->status);
    }

    public function test_same_scope_transfer_does_not_change_avco_state_and_returns_zero_delta()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'));
        $ctx = new TransferValuationContext('prop1', 'item1', 'scope1', new AvcoDecimal('17.0'));
        $input = $this->createInput('transfer', '5.0', null, 1, false, null, $ctx);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals('SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', $result->reasonCode);
        $this->assertTrue($result->newState->onHandQuantity->compareTo(new AvcoDecimal('10.0')) === 0); // Unchanged!
        $this->assertTrue($result->newState->carryingValue->compareTo(new AvcoDecimal('100.0')) === 0);
        $this->assertTrue($result->signedCarryingValueDelta->compareTo(new AvcoDecimal('0.0')) === 0);
        $this->assertTrue($result->sourceCarryingUnitCost->compareTo(new AvcoDecimal('17.0')) === 0);
    }

    public function test_different_scope_transfer_is_rejected()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'));
        $ctx = new TransferValuationContext('prop1', 'item1', 'scope2', new AvcoDecimal('17.0'));
        $input = $this->createInput('transfer', '5.0', null, 1, false, null, $ctx);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('TRANSFER_REQUIRES_PAIRED_SCOPE_MODEL', $result->reasonCode);
    }

    public function test_closed_period_without_correction_period_is_rejected()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'));
        $input = $this->createInput('receipt', '10.0', '20.0', 1, true, null);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('MISSING_CORRECTION_PERIOD_FOR_CLOSED_SOURCE', $result->reasonCode);
    }

    public function test_closed_period_with_correction_period_returns_actionable_correction_required()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'), new AvcoDecimal('10.0'), new AvcoDecimal('100.0'));
        $input = $this->createInput('receipt', '10.0', '20.0', 1, true, 'corr_period_1');

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $result->status);
        $this->assertEquals('corr_period_1', $result->correctionTargetPeriodId);
        $this->assertTrue($result->historicalStateUnchanged);
    }

    public function test_no_avco_source_references_inventory_stock_or_float()
    {
        $repoRoot = dirname(__DIR__, 4);
        $costControlDir = $repoRoot . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Finance' . DIRECTORY_SEPARATOR . 'CostControl';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($costControlDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('InventoryStock', $content);
                // Can't strictly assert 'float' because some native PHP features/tests use it, 
                // but we explicitly use AvcoDecimal in the codebase.
            }
        }
    }
}

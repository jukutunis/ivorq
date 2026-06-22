<?php

namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\Services\AvcoValuationEngine;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;

class AvcoValuationEngineTest extends TestCase
{
    private function createInput(string $type, float $qty, ?float $basis, int $seq = 1, bool $closed = false, ?string $correctionId = null, ?float $sourceCost = null): AvcoValuationInput
    {
        $vSeq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', $seq);
        return new AvcoValuationInput('txn1', $vSeq, $type, $qty, $basis, '2026-01-01 10:00:00', $closed, $correctionId, $sourceCost, null);
    }

    public function test_receipt_recalculates_avco_deterministically()
    {
        $engine = new AvcoValuationEngine();
        $priorSeq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', 1);
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1', $priorSeq);
        $input = $this->createInput('receipt', 10.0, 20.0, 2);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals(20.0, $result->newState->onHandQuantity);
        $this->assertEquals(15.0, $result->newState->weightedAverageUnitCost);
        $this->assertEquals(300.0, $result->newState->carryingValue);
    }

    public function test_issue_within_available_quantity_uses_prevailing_carrying_cost()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(20.0, 15.0, 300.0, 'scope1');
        $input = $this->createInput('issue', -5.0, null, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals(15.0, $result->newState->onHandQuantity);
        $this->assertEquals(15.0, $result->newState->weightedAverageUnitCost);
        $this->assertEquals(225.0, $result->newState->carryingValue);
    }

    public function test_shortage_issue_relieves_only_available_quantity_and_becomes_provisional()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('issue', -15.0, null, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_PROVISIONAL, $result->status);
        $this->assertEquals(-5.0, $result->newState->onHandQuantity);
        $this->assertEquals(10.0, $result->newState->weightedAverageUnitCost); // Prevailing unchanged for shortage
        $this->assertEquals(0.0, $result->newState->carryingValue); // 100 - (10 * 10) = 0
        $this->assertEquals(100.0, $result->transactionValue); // known relief
        $this->assertEquals(5.0, $result->newState->unresolvedProvisionalQuantity);
    }

    public function test_receipt_after_unresolved_provisional_balance_does_not_finalise_avco()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(-5.0, 10.0, 0.0, 'scope1', null, 5.0);
        $input = $this->createInput('receipt', 10.0, 20.0, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_PENDING, $result->status);
    }

    public function test_transfer_uses_source_carrying_cost_that_differs_from_target_avco()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        // Source cost 17, transfer qty +5. Target AVCO should NOT be recalculated (stays 10). Value uses 17.
        $input = $this->createInput('transfer', 5.0, null, 1, false, null, 17.0);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals(15.0, $result->newState->onHandQuantity);
        $this->assertEquals(10.0, $result->newState->weightedAverageUnitCost); // Target AVCO not updated
        $this->assertEquals(185.0, $result->newState->carryingValue); // 100 + (5 * 17)
        $this->assertEquals(85.0, $result->transactionValue);
    }

    public function test_positive_adjustment_without_approved_basis_remains_pending()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('adjustment', 5.0, null, 1);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_PENDING, $result->status);
    }

    public function test_out_of_order_and_duplicate_sequence_are_rejected()
    {
        $engine = new AvcoValuationEngine();
        $priorSeq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', 2);
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1', $priorSeq);
        
        $duplicateInput = $this->createInput('receipt', 10.0, 20.0, 2);
        $duplicateResult = $engine->evaluate($duplicateInput, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $duplicateResult->status);
        $this->assertEquals('OUT_OF_ORDER_OR_DUPLICATE_SEQUENCE', $duplicateResult->reasonCode);

        $outOfOrderInput = $this->createInput('receipt', 10.0, 20.0, 1);
        $outOfOrderResult = $engine->evaluate($outOfOrderInput, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $outOfOrderResult->status);
    }

    public function test_scope_mismatch_is_rejected()
    {
        $engine = new AvcoValuationEngine();
        $priorSeq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', 1);
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1', $priorSeq);

        $seq = new ValuationSequence('prop1', 'item1', 'scope2', '2026-01-01', 2);
        $input = new AvcoValuationInput('txn1', $seq, 'receipt', 10.0, 20.0, '2026-01-01 10:00:00', false);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('SCOPE_MISMATCH', $result->reasonCode);
    }

    public function test_closed_period_without_correction_period_is_rejected()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('receipt', 10.0, 20.0, 1, true, null);

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_REJECTED, $result->status);
        $this->assertEquals('MISSING_CORRECTION_PERIOD_FOR_CLOSED_SOURCE', $result->reasonCode);
    }

    public function test_closed_period_with_correction_period_returns_actionable_correction_required()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('receipt', 10.0, 20.0, 1, true, 'corr_period_1');

        $result = $engine->evaluate($input, $prior);
        $this->assertEquals(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $result->status);
        $this->assertEquals('corr_period_1', $result->correctionTargetPeriodId);
        $this->assertEquals('txn1', $result->originalTransactionReference);
    }

    public function test_no_avco_source_references_inventory_stock()
    {
        $repoRoot = dirname(__DIR__, 4);
        $costControlDir = $repoRoot . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Finance' . DIRECTORY_SEPARATOR . 'CostControl';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($costControlDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                $this->assertStringNotContainsString(
                    'InventoryStock',
                    $content,
                    "File {$file->getFilename()} contains forbidden InventoryStock reference."
                );
            }
        }
    }
}

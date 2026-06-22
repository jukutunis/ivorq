<?php

namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\Services\AvcoValuationEngine;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;
use InvalidArgumentException;

class AvcoValuationEngineTest extends TestCase
{
    private function createInput(string $type, float $qty, ?float $basis, bool $closed = false): AvcoValuationInput
    {
        $seq = new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', 1);
        return new AvcoValuationInput('txn1', $seq, $type, $qty, $basis, '2026-01-01 10:00:00', $closed);
    }

    public function test_receipt_recalculates_avco_deterministically()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('receipt', 10.0, 20.0);

        $result = $engine->evaluate($input, $prior);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals(20.0, $result->newState->onHandQuantity);
        $this->assertEquals(15.0, $result->newState->weightedAverageUnitCost);
        $this->assertEquals(300.0, $result->newState->carryingValue);
    }

    public function test_issue_uses_prevailing_carrying_cost()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(20.0, 15.0, 300.0, 'scope1');
        $input = $this->createInput('issue', -5.0, null);

        $result = $engine->evaluate($input, $prior);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals(15.0, $result->newState->onHandQuantity);
        $this->assertEquals(15.0, $result->newState->weightedAverageUnitCost);
        $this->assertEquals(225.0, $result->newState->carryingValue);
    }

    public function test_positive_adjustment_without_basis_returns_pending()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('adjustment', 5.0, null);

        $result = $engine->evaluate($input, $prior);

        $this->assertEquals(AvcoValuationResult::STATUS_PENDING, $result->status);
    }

    public function test_negative_resulting_stock_returns_provisional()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('issue', -15.0, null);

        $result = $engine->evaluate($input, $prior);

        $this->assertEquals(AvcoValuationResult::STATUS_PROVISIONAL, $result->status);
        $this->assertEquals(-5.0, $result->newState->onHandQuantity);
        $this->assertNull($result->newState->weightedAverageUnitCost);
    }

    public function test_transfer_preserves_carrying_cost_and_does_not_recalculate_avco()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('transfer', 5.0, null);

        $result = $engine->evaluate($input, $prior);

        $this->assertEquals(AvcoValuationResult::STATUS_FINAL, $result->status);
        $this->assertEquals(15.0, $result->newState->onHandQuantity);
        $this->assertEquals(10.0, $result->newState->weightedAverageUnitCost);
    }

    public function test_closed_period_backdated_effect_returns_correction_required()
    {
        $engine = new AvcoValuationEngine();
        $prior = new AvcoValuationState(10.0, 10.0, 100.0, 'scope1');
        $input = $this->createInput('receipt', 10.0, 20.0, true);

        $result = $engine->evaluate($input, $prior);

        $this->assertEquals(AvcoValuationResult::STATUS_CORRECTION_REQUIRED, $result->status);
    }

    public function test_valuation_sequence_rejects_zero_or_negative_sequence()
    {
        $this->expectException(InvalidArgumentException::class);
        new ValuationSequence('prop1', 'item1', 'scope1', '2026-01-01', 0);
    }

    public function test_no_new_costcontrol_avco_class_references_inventory_stock()
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

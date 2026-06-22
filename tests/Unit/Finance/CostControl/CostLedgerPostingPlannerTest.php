<?php
namespace Tests\Unit\Finance\CostControl;

use PHPUnit\Framework\TestCase;
use Modules\Finance\CostControl\Services\CostLedgerPostingPlanner;
use Modules\Finance\CostControl\Services\CostLedgerPostingGuard;
use Modules\Finance\CostControl\Services\AvcoValuationEngine;
use Modules\Finance\CostControl\ValueObjects\ApprovedInventoryEvidence;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingWindow;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Finance\CostControl\ValueObjects\TransferValuationContext;
use Modules\Finance\CostControl\Contracts\FutureLockOrderContract;
use InvalidArgumentException;

class CostLedgerPostingPlannerTest extends TestCase
{
    private function createEvidence(
        string $type, string $qty, ?string $basis, string $status = 'approved', ?string $ref = 'ref1',
        string $propId = 'prop1', string $itemId = 'item1', string $scope = 'scope1',
        ?TransferValuationContext $ctx = null, string $currency = 'USD', string $businessDate = '2026-01-01',
        string $occurredAt = '2026-01-01 10:00:00'
    ): ApprovedInventoryEvidence {
        return new ApprovedInventoryEvidence(
            'inv1', 'txn1', $propId, $itemId, $scope, $currency, $businessDate, $occurredAt,
            $type, new AvcoDecimal($qty), $basis !== null ? new AvcoDecimal($basis) : null,
            $ctx, 'idemp1', 1, $status, $ref
        );
    }

    private function createWindow(bool $propOpen = true, bool $finOpen = true, ?string $corrDate = null, ?string $corrFin = null, string $propId = 'prop1', string $sourceDate = '2026-01-01'): CostLedgerPostingWindow
    {
        return new CostLedgerPostingWindow($propId, $sourceDate, $propOpen, $finOpen, $corrDate, $corrFin);
    }

    private function createState(string $propId = 'prop1', string $itemId = 'item1', string $scope = 'scope1', string $qty = '10.0', string $unit = '10.0', string $val = '100.0'): AvcoValuationState
    {
        $seq = new ValuationSequence($propId, $itemId, $scope, '2025-12-31', 1);
        return new AvcoValuationState($propId, $itemId, $scope, new AvcoDecimal($qty), new AvcoDecimal($unit), new AvcoDecimal($val), $seq);
    }

    public function test_receipt_intent_unit_cost_equals_source_approved_basis()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1', 'scope1', '10.0', '10.0', '100.0');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('allow', $plan->decision->status);
        $this->assertTrue($plan->intent->unitCost->compareTo(new AvcoDecimal('20.0')) === 0);
        $this->assertTrue($plan->intent->valueDelta->compareTo(new AvcoDecimal('200.0')) === 0);
    }

    public function test_full_stock_issue_uses_prior_avco_as_intent_unit_cost()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('issue', '-10.0', null);
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1', 'scope1', '10.0', '10.0', '100.0');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('allow', $plan->decision->status);
        $this->assertTrue($plan->intent->unitCost->compareTo(new AvcoDecimal('10.0')) === 0);
        $this->assertTrue($plan->intent->valueDelta->compareTo(new AvcoDecimal('-100.0')) === 0);
    }

    public function test_positive_adjustment_maps_to_adjustment_and_uses_basis()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('positive_adjustment', '5.0', '12.0');
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('adjustment', $plan->intent->entryType);
        $this->assertEquals('positive_adjustment', $plan->intent->metadata['adjustment_direction']);
        $this->assertTrue($plan->intent->unitCost->compareTo(new AvcoDecimal('12.0')) === 0);
    }

    public function test_negative_adjustment_maps_to_adjustment_and_uses_prior_avco()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('negative_adjustment', '-5.0', null);
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1', 'scope1', '10.0', '10.0', '100.0');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('adjustment', $plan->intent->entryType);
        $this->assertEquals('negative_adjustment', $plan->intent->metadata['adjustment_direction']);
        $this->assertTrue($plan->intent->unitCost->compareTo(new AvcoDecimal('10.0')) === 0);
        $this->assertTrue($plan->intent->valueDelta->compareTo(new AvcoDecimal('-50.0')) === 0);
    }

    public function test_provisional_shortage_decision_pending_no_intent()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('issue', '-15.0', null);
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1', 'scope1', '10.0', '10.0', '100.0');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('pending', $plan->decision->status);
        $this->assertEquals('PROVISIONAL_VALUATION_REQUIRES_RESOLUTION', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
    }

    public function test_same_scope_transfer()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $ctx = new TransferValuationContext('prop1', 'item1', 'scope1', new AvcoDecimal('10.0'));
        $evidence = $this->createEvidence('transfer', '5.0', null, 'approved', 'ref1', 'prop1', 'item1', 'scope1', $ctx);
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1', 'scope1', '10.0', '10.0', '100.0');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('allow', $plan->decision->status);
        $this->assertNull($plan->intent);
        $this->assertEquals('SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', $plan->decision->reasonCode);
        $this->assertTrue($plan->resultingState->onHandQuantity->compareTo(new AvcoDecimal('10.0')) === 0);
        $this->assertTrue($plan->resultingState->carryingValue->compareTo(new AvcoDecimal('100.0')) === 0);
    }

    public function test_cross_scope_transfer_rejected()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $ctx = new TransferValuationContext('prop1', 'item1', 'scope2', new AvcoDecimal('10.0'));
        $evidence = $this->createEvidence('transfer', '5.0', null, 'approved', 'ref1', 'prop1', 'item1', 'scope1', $ctx);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('TRANSFER_REQUIRES_PAIRED_SCOPE_MODEL', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
    }

    public function test_approved_evidence_without_reference_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createEvidence('receipt', '10.0', '20.0', 'approved', '');
    }

    public function test_pending_rejected_cannot_carry_reference()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createEvidence('receipt', '10.0', '20.0', 'pending', 'ref1');
    }

    public function test_pending_approval_evidence_produces_source_not_approved()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'pending', null);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('SOURCE_NOT_APPROVED', $plan->decision->reasonCode);
    }

    public function test_invalid_currency_examples_rejected()
    {
        $invalidCurrencies = ['usd', 'US', 'USDD', 'U5D'];
        foreach ($invalidCurrencies as $currency) {
            $thrown = false;
            try {
                $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1', null, $currency);
            } catch (InvalidArgumentException $e) {
                $thrown = true;
            }
            $this->assertTrue($thrown, "Currency $currency should throw");
        }
    }

    public function test_invalid_source_correction_dates_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createWindow(true, true, '2026-02-31', 'fin1');
    }

    public function test_invalid_correction_date_before_source()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createWindow(true, true, '2025-12-31', 'fin1', 'prop1', '2026-01-01');
    }

    public function test_invalid_correction_date_without_period()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createWindow(true, true, '2026-01-02', null);
    }

    public function test_invalid_correction_period_without_date()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createWindow(true, true, null, 'fin1');
    }

    public function test_planner_production_source_no_forbidden()
    {
        $repoRoot = dirname(__DIR__, 4);
        $files = [
            'Modules/Finance/CostControl/Services/CostLedgerPostingPlanner.php',
            'Modules/Finance/CostControl/Services/CostLedgerPostingGuard.php'
        ];
        
        $forbidden = [
            'InventoryStock',
            '::query(', '->where(', '->first(', '->find(', 'DB::', 'Model::',
            '->save(', '->create(', '->update(', '->delete(',
            'CostLedgerAppendService', 'CostLedgerRepository',
            'GeneralLedger', 'Journal', 'AccountsPayable', 'Payable', 'GRNI',
            'Event::', 'Queue::', 'dispatch(', 'Observer', 'Listener', 'Controller', 'Command',
            'AvcoDecimal::zero()'
        ];

        foreach ($files as $relPath) {
            $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $content = file_get_contents($path);
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsString($f, $content, "File $relPath contains forbidden $f");
            }
        }
    }
}
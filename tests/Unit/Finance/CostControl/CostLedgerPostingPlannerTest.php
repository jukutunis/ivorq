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
    private function createEvidence(string $type, string $qty, ?string $basis, bool $approved = true, string $propId = 'prop1', string $itemId = 'item1', string $scope = 'scope1', ?TransferValuationContext $ctx = null): ApprovedInventoryEvidence
    {
        return new ApprovedInventoryEvidence(
            'inv1', 'txn1', $propId, $itemId, $scope, 'USD', '2026-01-01', '2026-01-01 10:00:00',
            $type, new AvcoDecimal($qty), $basis !== null ? new AvcoDecimal($basis) : null,
            $ctx, 'idemp1', 1, $approved
        );
    }

    private function createWindow(bool $propOpen = true, bool $finOpen = true, ?string $corrDate = null, ?string $corrFin = null, string $propId = 'prop1'): CostLedgerPostingWindow
    {
        return new CostLedgerPostingWindow($propId, '2026-01-01', $propOpen, $finOpen, $corrDate, $corrFin);
    }

    private function createState(string $propId = 'prop1', string $itemId = 'item1', string $scope = 'scope1', string $qty = '10.0', string $unit = '10.0', string $val = '100.0'): AvcoValuationState
    {
        $seq = new ValuationSequence($propId, $itemId, $scope, '2025-12-31', 1);
        return new AvcoValuationState($propId, $itemId, $scope, new AvcoDecimal($qty), new AvcoDecimal($unit), new AvcoDecimal($val), $seq);
    }

    public function test_intent_accepts_avcodecimal_and_rejects_float()
    {
        $intent = new CostLedgerEntryIntent(
            'prop1', 'inv1', null, 'receipt', 'key', 1, 'USD',
            new AvcoDecimal('1.0'), new AvcoDecimal('10.0'), new AvcoDecimal('10.0'),
            '2026-01-01', '2026-01-01 10:00:00'
        );
        $this->assertInstanceOf(AvcoDecimal::class, $intent->quantityDelta);
        $this->assertTrue($intent->quantityDelta->compareTo(new AvcoDecimal('1.0')) === 0);
    }

    public function test_production_contracts_contain_no_float()
    {
        $repoRoot = dirname(__DIR__, 4);
        $files = [
            'Modules/Finance/CostControl/ValueObjects/CostLedgerEntryIntent.php',
            'Modules/Finance/CostControl/Repositories/CostLedgerRepository.php'
        ];
        foreach ($files as $relPath) {
            $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $content = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/\bfloat\b/', $content, "File $relPath contains float");
        }
    }

    public function test_approved_open_receipt_produces_allow_and_intent()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('allow', $plan->decision->status);
        $this->assertNotNull($plan->intent);
        $this->assertEquals('receipt', $plan->intent->entryType);
        $this->assertTrue($plan->intent->quantityDelta->compareTo(new AvcoDecimal('10.0')) === 0);
    }

    public function test_unapproved_evidence_produces_source_not_approved()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', false);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('SOURCE_NOT_APPROVED', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
    }

    public function test_evidence_window_property_mismatch_rejected()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', true, 'prop2');
        $window = $this->createWindow(true, true, null, null, 'prop1');
        $prior = $this->createState('prop1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_evidence_state_item_mismatch_rejected()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', true, 'prop1', 'item2');
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_evidence_state_valuation_scope_mismatch_rejected()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', true, 'prop1', 'item1', 'scope2');
        $window = $this->createWindow();
        $prior = $this->createState('prop1', 'item1', 'scope1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_closed_source_business_date_with_correction_context()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow(false, true, '2026-02-01', 'fin1');
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('correction_required', $plan->decision->status);
        $this->assertNull($plan->intent);
        $this->assertEquals('txn1', $plan->decision->originalTransactionReference);
        $this->assertEquals('2026-01-01', $plan->decision->originalBusinessDate);
        $this->assertTrue($plan->decision->historicalStateUnchanged);
    }

    public function test_closed_source_business_date_without_correction_context()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow(false, true);
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_closed_financial_period_with_correction_context()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow(true, false, '2026-02-01', 'fin1');
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('correction_required', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_closed_financial_period_without_correction_context()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow(true, false);
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_avco_pending_result_creates_no_intent()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('issue', '-10.0', null);
        $window = $this->createWindow();
        $prior = new AvcoValuationState('prop1', 'item1', 'scope1', new AvcoDecimal('0.0'), null, new AvcoDecimal('0.0'));

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('pending', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_avco_provisional_shortage_result()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('issue', '-15.0', null);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('allow', $plan->decision->status);
        $this->assertNotNull($plan->intent);
        $this->assertEquals('issue', $plan->intent->entryType);
        $this->assertEquals('5.0000', $plan->intent->metadata['provisional_unresolved_qty']);
        $this->assertEquals('-100.0000', $plan->intent->metadata['provisional_relieved_value']);
        // carrying value delta is exactly the known prior carrying value, no unit cost fabrication
        $this->assertTrue($plan->intent->valueDelta->compareTo(new AvcoDecimal('-100.0')) === 0);
        $this->assertTrue($plan->intent->unitCost->compareTo(new AvcoDecimal('0.0')) === 0);
    }

    public function test_same_scope_transfer_valuation_neutral()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $ctx = new TransferValuationContext('prop1', 'item1', 'scope1', new AvcoDecimal('17.0'));
        $evidence = $this->createEvidence('transfer', '5.0', null, true, 'prop1', 'item1', 'scope1', $ctx);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('allow', $plan->decision->status);
        $this->assertNotNull($plan->intent);
        $this->assertEquals('transfer', $plan->intent->entryType);
        $this->assertTrue($plan->intent->valueDelta->compareTo(new AvcoDecimal('0.0')) === 0);
    }

    public function test_cross_scope_transfer_rejected()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $ctx = new TransferValuationContext('prop1', 'item1', 'scope2', new AvcoDecimal('17.0'));
        $evidence = $this->createEvidence('transfer', '5.0', null, true, 'prop1', 'item1', 'scope1', $ctx);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertNull($plan->intent);
    }

    public function test_planner_source_contains_no_forbidden_dependencies()
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
            'Event::', 'Queue::', 'dispatch(', 'Observer', 'Listener', 'Controller', 'Command'
        ];

        foreach ($files as $relPath) {
            $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $content = file_get_contents($path);
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsString($f, $content, "File $relPath contains forbidden $f");
            }
        }
    }

    public function test_future_lock_order_contract_exists()
    {
        $this->assertEquals('PropertyBusinessDate -> FinancialPeriod -> InventoryStock', FutureLockOrderContract::REQUIRED_LOCK_ORDER);
    }
}
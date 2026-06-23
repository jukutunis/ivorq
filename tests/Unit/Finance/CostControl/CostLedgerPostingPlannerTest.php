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
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingDecision;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingPlan;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationInput;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationResult;

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

    private function createState(string $propId = 'prop1', string $itemId = 'item1', string $scope = 'scope1', string $qty = '10.0', ?string $unit = '10.0', string $val = '100.0', string $unresolved = '0.0'): AvcoValuationState
    {
        $seq = new ValuationSequence($propId, $itemId, $scope, '2025-12-31', 1);
        return new AvcoValuationState($propId, $itemId, $scope, new AvcoDecimal($qty), $unit !== null ? new AvcoDecimal($unit) : null, new AvcoDecimal($val), $seq, new AvcoDecimal($unresolved));
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
    
    public function test_whitespace_rejection()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createEvidence('receipt', '10.0', '20.0', 'approved', '   ');
    }

    public function test_prior_unresolved_provisional_balance()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $prior = $this->createState('prop1', 'item1', 'scope1', '-5.0', null, '0.0', '5.0');
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('pending', $plan->decision->status);
        $this->assertEquals('PRIOR_UNRESOLVED_PROVISIONAL_BALANCE_EXISTS', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNotNull($plan->valuationResult);
        $this->assertEquals('correction_required', $plan->valuationResult->status);
    }

    public function test_unexpected_avco_correction_path()
    {
        /** @var AvcoValuationEngine|MockObject $engine */
        $engine = $this->createMock(AvcoValuationEngine::class);
        $prior = $this->createState();
        $engine->method('evaluate')->willReturn(new AvcoValuationResult('correction_required', $prior, null, 'SOME_WEIRD_REASON'));
        
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), $engine);
        $evidence = $this->createEvidence('receipt', '10.0', '20.0');
        $window = $this->createWindow();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('UNSUPPORTED_AVCO_CORRECTION_CONTEXT', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
    }

    public function test_representability_guard()
    {
        /** @var AvcoValuationEngine|MockObject $engine */
        $engine = $this->createMock(AvcoValuationEngine::class);
        $prior = $this->createState('prop1', 'item1', 'scope1', '10.0', '10.0', '100.0'); 
        $engine->method('evaluate')->willReturn(new AvcoValuationResult('final', $prior, null, null)); // null value delta
        
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), $engine);
        // pass null basis, resulting in null unitCost in intent
        $evidence = $this->createEvidence('receipt', '5.0', null); 
        $window = $this->createWindow();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('pending', $plan->decision->status);
        $this->assertEquals('UNREPRESENTABLE_EVENT_VALUATION', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
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

    // --- Decision Contract Tests ---
    public function test_decision_correction_required_with_invalid_target_date() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('correction_required', 'R', true, '2026-02-31', 'P1', 'REF', '2026-01-01');
    }

    public function test_decision_correction_required_with_invalid_original_date() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('correction_required', 'R', true, '2026-01-01', 'P1', 'REF', '2026-13-01');
    }

    public function test_decision_correction_required_with_blank_period_id() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('correction_required', 'R', true, '2026-01-01', '  ', 'REF', '2026-01-01');
    }

    public function test_decision_allow_with_original_reference() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('allow', 'R', false, null, null, 'REF', null);
    }

    public function test_decision_pending_with_correction_target() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('pending', 'R', true, '2026-01-01', 'P1');
    }

    public function test_decision_rejected_with_correction_target() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('rejected', 'R', true, '2026-01-01', 'P1');
    }

    public function test_decision_pending_with_invalid_diagnostic_date() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('pending', 'R', true, null, null, 'REF', '2026-1-1');
    }

    public function test_decision_whitespace_reason_code() {
        $this->expectException(InvalidArgumentException::class);
        new CostLedgerPostingDecision('allow', '   ', false);
    }

    // --- Plan Contract Tests ---
    public function test_plan_pending_with_intent() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('pending', 'R', true);
        $state = $this->createState();
        $intent = new CostLedgerEntryIntent('prop1', 'T', null, 'receipt', 'I', 1, 'USD', new AvcoDecimal('1'), new AvcoDecimal('1'), new AvcoDecimal('1'), '2026-01-01', '2026-01-01 10:00:00');
        new CostLedgerPostingPlan($decision, $state, $state, null, $intent, 'REF', 'I');
    }

    public function test_plan_rejected_with_cloned_state() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('rejected', 'R', true);
        $state1 = $this->createState();
        $state2 = clone $state1;
        new CostLedgerPostingPlan($decision, $state1, $state2, null, null, 'REF', 'I');
    }

    public function test_plan_correction_required_with_non_identical_state() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('correction_required', 'R', true, '2026-01-01', 'P1', 'REF', '2026-01-01');
        $state1 = $this->createState();
        $state2 = $this->createState('prop1', 'item2');
        new CostLedgerPostingPlan($decision, $state1, $state2, null, null, 'REF', 'I');
    }

    public function test_plan_allow_null_intent_non_transfer() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'OTHER', false);
        $state = $this->createState();
        $result = new AvcoValuationResult('final', $state, null, 'OTHER');
        new CostLedgerPostingPlan($decision, $state, $state, $result, null, 'REF', 'I');
    }

    public function test_plan_allow_intent_null_result() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'R', false);
        $state = $this->createState();
        $intent = new CostLedgerEntryIntent('prop1', 'T', null, 'receipt', 'I', 1, 'USD', new AvcoDecimal('1'), new AvcoDecimal('1'), new AvcoDecimal('1'), '2026-01-01', '2026-01-01 10:00:00');
        new CostLedgerPostingPlan($decision, $state, $state, null, $intent, 'REF', 'I');
    }

    public function test_plan_allow_intent_non_final_result() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'R', false);
        $state = $this->createState();
        $result = new AvcoValuationResult('pending', $state);
        $intent = new CostLedgerEntryIntent('prop1', 'T', null, 'receipt', 'I', 1, 'USD', new AvcoDecimal('1'), new AvcoDecimal('1'), new AvcoDecimal('1'), '2026-01-01', '2026-01-01 10:00:00');
        new CostLedgerPostingPlan($decision, $state, $state, $result, $intent, 'REF', 'I');
    }

    public function test_plan_allow_intent_state_mismatch() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'R', false);
        $state1 = $this->createState();
        $state2 = clone $state1;
        $result = new AvcoValuationResult('final', $state1);
        $intent = new CostLedgerEntryIntent('prop1', 'T', null, 'receipt', 'I', 1, 'USD', new AvcoDecimal('1'), new AvcoDecimal('1'), new AvcoDecimal('1'), '2026-01-01', '2026-01-01 10:00:00');
        new CostLedgerPostingPlan($decision, $state1, $state2, $result, $intent, 'REF', 'I');
    }

    public function test_plan_transfer_allow_null_result() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', false);
        $state = $this->createState();
        new CostLedgerPostingPlan($decision, $state, $state, null, null, 'REF', 'I');
    }

    public function test_plan_transfer_allow_non_final_result() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'SAME_SCOPE_TRANSFER_VALUATION_NEUTRAL', false);
        $state = $this->createState();
        $result = new AvcoValuationResult('pending', $state);
        new CostLedgerPostingPlan($decision, $state, $state, $result, null, 'REF', 'I');
    }

    public function test_plan_allow_mismatched_intent_property() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'R', false);
        $state = $this->createState('prop1');
        $result = new AvcoValuationResult('final', $state);
        $intent = new CostLedgerEntryIntent('prop2', 'T', null, 'receipt', 'I', 1, 'USD', new AvcoDecimal('1'), new AvcoDecimal('1'), new AvcoDecimal('1'), '2026-01-01', '2026-01-01 10:00:00');
        new CostLedgerPostingPlan($decision, $state, $state, $result, $intent, 'REF', 'I');
    }

    public function test_plan_allow_mismatched_idempotency_key() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('allow', 'R', false);
        $state = $this->createState('prop1');
        $result = new AvcoValuationResult('final', $state);
        $intent = new CostLedgerEntryIntent('prop1', 'T', null, 'receipt', 'I1', 1, 'USD', new AvcoDecimal('1'), new AvcoDecimal('1'), new AvcoDecimal('1'), '2026-01-01', '2026-01-01 10:00:00');
        new CostLedgerPostingPlan($decision, $state, $state, $result, $intent, 'REF', 'I2');
    }

    public function test_plan_whitespace_reference() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('pending', 'R', true);
        $state = $this->createState();
        new CostLedgerPostingPlan($decision, $state, $state, null, null, '   ', 'I');
    }

    public function test_plan_whitespace_idempotency() {
        $this->expectException(InvalidArgumentException::class);
        $decision = new CostLedgerPostingDecision('pending', 'R', true);
        $state = $this->createState();
        new CostLedgerPostingPlan($decision, $state, $state, null, null, 'REF', '   ');
    }

    // --- Guard Regression Tests ---

    public function test_property_window_mismatch()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop2', 'item1', 'scope1');
        $window = $this->createWindow(true, true, null, null, 'prop1');
        $prior = $this->createState('prop2', 'item1', 'scope1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('PROPERTY_MISMATCH_WITH_WINDOW', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_property_state_mismatch()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1');
        $window = $this->createWindow(true, true, null, null, 'prop1');
        $prior = $this->createState('prop2', 'item1', 'scope1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('PROPERTY_MISMATCH_WITH_STATE', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_item_mismatch()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item2', 'scope1');
        $window = $this->createWindow(true, true, null, null, 'prop1');
        $prior = $this->createState('prop1', 'item1', 'scope1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('ITEM_MISMATCH_WITH_STATE', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_valuation_scope_mismatch()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope2');
        $window = $this->createWindow(true, true, null, null, 'prop1');
        $prior = $this->createState('prop1', 'item1', 'scope1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('SCOPE_MISMATCH_WITH_STATE', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_business_date_mismatch()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1', null, 'USD', '2026-01-02');
        $window = $this->createWindow(true, true, null, null, 'prop1', '2026-01-01');
        $prior = $this->createState('prop1', 'item1', 'scope1');

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('BUSINESS_DATE_MISMATCH_WITH_WINDOW', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_rejected_approval()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'rejected', null);
        $window = $this->createWindow();
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('SOURCE_NOT_APPROVED', $plan->decision->reasonCode);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_closed_business_date_with_correction()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1', null, 'USD', '2026-01-01');
        $window = $this->createWindow(false, true, '2026-01-02', 'correction-period-1', 'prop1', '2026-01-01');
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('correction_required', $plan->decision->status);
        $this->assertEquals('SOURCE_BUSINESS_DATE_CLOSED', $plan->decision->reasonCode);
        $this->assertTrue($plan->decision->historicalStateUnchanged);
        $this->assertEquals('2026-01-02', $plan->decision->targetCorrectionBusinessDate);
        $this->assertEquals('correction-period-1', $plan->decision->targetCorrectionPeriodId);
        $this->assertEquals('txn1', $plan->decision->originalTransactionReference);
        $this->assertEquals('2026-01-01', $plan->decision->originalBusinessDate);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_closed_business_date_without_correction()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1', null, 'USD', '2026-01-01');
        $window = $this->createWindow(false, true, null, null, 'prop1', '2026-01-01');
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('MISSING_OPEN_CORRECTION_CONTEXT', $plan->decision->reasonCode);
        $this->assertTrue($plan->decision->historicalStateUnchanged);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_closed_financial_period_with_correction()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1', null, 'USD', '2026-01-01');
        $window = $this->createWindow(true, false, '2026-01-02', 'correction-period-1', 'prop1', '2026-01-01');
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('correction_required', $plan->decision->status);
        $this->assertEquals('SOURCE_FINANCIAL_PERIOD_CLOSED', $plan->decision->reasonCode);
        $this->assertTrue($plan->decision->historicalStateUnchanged);
        $this->assertEquals('2026-01-02', $plan->decision->targetCorrectionBusinessDate);
        $this->assertEquals('correction-period-1', $plan->decision->targetCorrectionPeriodId);
        $this->assertEquals('txn1', $plan->decision->originalTransactionReference);
        $this->assertEquals('2026-01-01', $plan->decision->originalBusinessDate);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }

    public function test_closed_financial_period_without_correction()
    {
        $planner = new CostLedgerPostingPlanner(new CostLedgerPostingGuard(), new AvcoValuationEngine());
        $evidence = $this->createEvidence('receipt', '10.0', '20.0', 'approved', 'ref1', 'prop1', 'item1', 'scope1', null, 'USD', '2026-01-01');
        $window = $this->createWindow(true, false, null, null, 'prop1', '2026-01-01');
        $prior = $this->createState();

        $plan = $planner->plan($evidence, $window, $prior);
        $this->assertEquals('rejected', $plan->decision->status);
        $this->assertEquals('MISSING_OPEN_CORRECTION_CONTEXT', $plan->decision->reasonCode);
        $this->assertTrue($plan->decision->historicalStateUnchanged);
        $this->assertNull($plan->intent);
        $this->assertSame($prior, $plan->resultingState);
        $this->assertNull($plan->valuationResult);
    }
}
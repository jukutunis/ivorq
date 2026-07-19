<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\Postgres\Operations\PMS\Support\GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutTerminalFinancialAttestationService $service;
    private PropertyBusinessDateOperationalLockService $lockService;
    private GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]]; }
        });
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]]; }
        });
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]]; }
        });

        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);
        $this->c = new GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator();
    }

    protected function tearDown(): void { $this->c->cleanup(); parent::tearDown(); }

    // ── Helpers ──
    private function openBD(): PropertyBusinessDate {
        $bd = new PropertyBusinessDate(); $bd->forceFill(['property_id'=>$this->glfProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfActor->id,'opened_at'=>now()])->save(); return $bd->fresh();
    }
    private function ev(PropertyBusinessDate $bd): array {
        return ['property_business_date_id'=>$bd->id,'property_id'=>$this->glfProperty->id,'business_date'=>$bd->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfActor->id,'opened_at'=>$bd->opened_at->utc()->toISOString()];
    }
    private function stay(string $rid, string $gid): FrontDeskStay {
        $s = new FrontDeskStay(); $s->forceFill(['property_id'=>$this->glfProperty->id,'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id])->save(); return $s->fresh();
    }
    private function folio(string $rid, string $gid): Folio { static $n=0; $n++; $f=new Folio(); $f->forceFill(['property_id'=>$this->glfProperty->id,'folio_number'=>'CP-'.$n.'-'.bin2hex(random_bytes(2)),'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>'open','currency'=>'USD','window_number'=>$n,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'cp-'.bin2hex(random_bytes(4))])->save(); return $f->fresh(); }
    private function tmp(string $prefix): string { return sys_get_temp_dir().'/'.uniqid($prefix.'_'); }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario A — participant first: Worker A holds, B blocked, B completes after A
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_a_participant_first(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $f = $this->folio($r->id, $g->id);
        $bd = $this->openBD();

        $readyA = $this->tmp('a_ready');
        $holdA  = $this->tmp('a_hold');

        // Worker A: attest and hold
        $wA = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,
            'ready_marker'=>$readyA,'hold_until_path'=>$holdA,'hold_timeout'=>20,
        ]);

        // Wait for Worker A readiness (attest done, locks held)
        $mA = $this->c->waitForMarker($readyA, 15);
        $this->assertEquals('attest', $mA['mode']);
        $this->assertTrue($this->c->isWorkerRunning($wA));

        // Worker B: attempt mutation on locked folio
        $wB = $this->c->spawnWorker('mutate', [
            'table'=>'folios','row_id'=>$f->id,'column'=>'total_charges','value'=>'999.99',
        ]);
        usleep(500000); // B should be blocked
        $this->assertTrue($this->c->isWorkerRunning($wB), 'Worker B must be blocked behind A locks.');

        // Release A
        $this->c->releaseWorker($holdA);
        $rA = $this->c->waitForWorker($wA, 20);
        $this->assertNotEmpty($rA['postgres_backend_pid']);
        $this->assertNotEmpty($rA['postgres_transaction_id']);

        // B completes after A commit
        $rB = $this->c->waitForWorker($wB, 20);
        $this->assertNotEquals($rA['php_pid'], $rB['php_pid']);
        $this->assertNotEquals($rA['postgres_backend_pid'], $rB['postgres_backend_pid']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario B — mutation first: B locks source, A blocked, A reads committed state
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_b_mutation_first(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $f = $this->folio($r->id, $g->id);
        $bd = $this->openBD();

        $readyB = $this->tmp('b_ready');
        $holdB  = $this->tmp('b_hold');

        // Worker B: lock and mutate, hold
        $wB = $this->c->spawnWorker('hold_source', [
            'table'=>'folios','row_id'=>$f->id,
            'ready_marker'=>$readyB,'hold_until_path'=>$holdB,'hold_timeout'=>20,
        ]);

        // Wait for B to acquire lock
        $mB = $this->c->waitForMarker($readyB, 10);
        $this->assertEquals('hold_source', $mB['mode']);

        // Worker A: attempt GLF-E attest
        $wA = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,
        ]);
        usleep(500000);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Worker A must be blocked behind B lock.');

        // Release B
        $this->c->releaseWorker($holdB);
        $rB = $this->c->waitForWorker($wB, 20);

        // A completes — attestation reflects committed state
        $rA = $this->c->waitForWorker($wA, 20);
        $this->assertNotEquals($rA['php_pid'], $rB['php_pid']);
        $this->assertNotEquals($rA['postgres_backend_pid'], $rB['postgres_backend_pid']);
        $this->assertNotEmpty($rA['fingerprint']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario C — rollback release: A holds and rolls back, B completes
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_c_rollback_release(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $this->folio($r->id, $g->id);
        $bd = $this->openBD();

        $readyA = $this->tmp('c_ready');
        $holdA  = $this->tmp('c_hold');

        // Worker A: attest and hold, then rollback when released
        $wA = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,
            'ready_marker'=>$readyA,'hold_until_path'=>$holdA,'hold_timeout'=>20,
        ]);
        $this->c->waitForMarker($readyA, 15);

        // Release A (it will commit, releasing locks)
        $this->c->releaseWorker($holdA);
        $rA = $this->c->waitForWorker($wA, 20);
        $this->assertNotEmpty($rA['fingerprint']);

        // Worker B: attest after A releases — must succeed
        $wB = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,
        ]);
        $rB = $this->c->waitForWorker($wB, 20);
        $this->assertNotEquals($rA['php_pid'], $rB['php_pid']);
        $this->assertNotEquals($rA['postgres_backend_pid'], $rB['postgres_backend_pid']);
        $this->assertNotEmpty($rB['fingerprint']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario D — Property isolation
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_d_property_isolation(): void
    {
        // Property A setup
        $rA = $this->makeGlfReservation(); $gA = $rA->primaryGuest;
        $sA = $this->stay($rA->id, $gA->id); $this->folio($rA->id, $gA->id);
        $bdA = $this->openBD();

        // Property B setup
        $rB = $this->makeGlfReservation($this->glfOtherProperty);
        $rB->refresh(); $gB = $rB->primaryGuest;
        $sB = new FrontDeskStay(); $sB->forceFill(['property_id'=>$this->glfOtherProperty->id,'reservation_id'=>$rB->id,'guest_id'=>$gB->id,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfOtherActor->id,'updated_by'=>$this->glfOtherActor->id])->save();
        $fB = new Folio(); $fB->forceFill(['property_id'=>$this->glfOtherProperty->id,'folio_number'=>'CPB-'.bin2hex(random_bytes(2)),'reservation_id'=>$rB->id,'guest_id'=>$gB->id,'status'=>'open','currency'=>'USD','window_number'=>1,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'cpb-'.bin2hex(random_bytes(4))])->save();
        $bdB = new PropertyBusinessDate(); $bdB->forceFill(['property_id'=>$this->glfOtherProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfOtherActor->id,'opened_at'=>now()])->save();

        $readyA = $this->tmp('d_ready');
        $holdA  = $this->tmp('d_hold');

        // Worker A: hold Property A
        $wA = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$this->ev($bdA),'stay_id'=>$sA->id,
            'ready_marker'=>$readyA,'hold_until_path'=>$holdA,'hold_timeout'=>20,
        ]);
        $this->c->waitForMarker($readyA, 15);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Worker A must still hold Property A.');

        // Worker B: attest Property B — must complete while A holds
        $wB = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfOtherProperty->id,
            'expected_evidence'=>['property_business_date_id'=>$bdB->id,'property_id'=>$this->glfOtherProperty->id,'business_date'=>$bdB->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfOtherActor->id,'opened_at'=>$bdB->opened_at->utc()->toISOString()],
            'stay_id'=>$sB->id,
        ]);
        $rB = $this->c->waitForWorker($wB, 20);
        $this->assertNotEmpty($rB['postgres_backend_pid']);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Property B must not block Property A.');

        // Release A
        $this->c->releaseWorker($holdA);
        $rA = $this->c->waitForWorker($wA, 20);
        $this->assertNotEquals($rA['postgres_backend_pid'], $rB['postgres_backend_pid']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario E — actual lock timeout
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_e_lock_timeout(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $f = $this->folio($r->id, $g->id);
        $bd = $this->openBD();

        $readyE = $this->tmp('e_ready');
        $holdE  = $this->tmp('e_hold');

        // Worker B: hold source lock beyond 5s
        $wB = $this->c->spawnWorker('hold_source', [
            'table'=>'folios','row_id'=>$f->id,
            'ready_marker'=>$readyE,'hold_until_path'=>$holdE,'hold_timeout'=>20,
        ]);
        $mB = $this->c->waitForMarker($readyE, 10);
        $this->assertEquals('hold_source', $mB['mode']);

        // Worker A: attempt attest — must hit lock timeout
        $wA = $this->c->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,
        ]);

        try {
            $this->c->waitForWorker($wA, 20);
            $this->fail('Expected lock timeout exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT', $e->getMessage());
            $this->assertStringContainsString('sqlstate: 55P03', $e->getMessage());
            $this->assertStringContainsString('canceling statement due to lock timeout', $e->getMessage());
            $this->assertStringContainsString('prev: Illuminate', $e->getMessage());
        }

        // Release B
        $this->c->releaseWorker($holdE);
        $this->c->waitForWorker($wB, 20);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Narrow lock-timeout classifier
    // ═══════════════════════════════════════════════════════════════════════
    public function test_unrelated_55p03_not_classified_as_lock_timeout(): void
    {
        $ref = new \ReflectionMethod($this->service, 'isLockTimeout');
        $ref->setAccessible(true);

        $wrongSqlstate = new class extends \RuntimeException {
            public array $errorInfo = ['42P01', '7', 'some error'];
        };
        $this->assertFalse($ref->invoke($this->service, $wrongSqlstate));

        $wrongMessage = new class extends \RuntimeException {
            public array $errorInfo = ['55P03', '7', 'some other error'];
        };
        $this->assertFalse($ref->invoke($this->service, $wrongMessage));

        $correct = new class('canceling statement due to lock timeout') extends \RuntimeException {
            public array $errorInfo = ['55P03', '7', 'canceling statement due to lock timeout'];
        };
        $this->assertTrue($ref->invoke($this->service, $correct));
    }
}

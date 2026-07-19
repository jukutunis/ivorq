<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Folio;
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
    private GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator $c;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpGuestLedgerFolioFixture();
        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort { public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]]; } });
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort { public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]]; } });
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort { public function participate(string $r, string $p): array { return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]]; } });
        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->c = new GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator();
    }

    protected function tearDown(): void { $this->c->cleanup(); parent::tearDown(); }

    private function openBD(): PropertyBusinessDate { $bd=new PropertyBusinessDate(); $bd->forceFill(['property_id'=>$this->glfProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfActor->id,'opened_at'=>now()])->save(); return $bd->fresh(); }
    private function ev(PropertyBusinessDate $bd): array { return ['property_business_date_id'=>$bd->id,'property_id'=>$this->glfProperty->id,'business_date'=>$bd->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfActor->id,'opened_at'=>$bd->opened_at->utc()->toISOString()]; }
    private function stay(string $rid, string $gid): FrontDeskStay { $s=new FrontDeskStay(); $s->forceFill(['property_id'=>$this->glfProperty->id,'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id])->save(); return $s->fresh(); }
    private function folio(string $rid, string $gid): Folio { static $n=0; $n++; $f=new Folio(); $f->forceFill(['property_id'=>$this->glfProperty->id,'folio_number'=>'CP-'.$n.'-'.bin2hex(random_bytes(2)),'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>'open','currency'=>'USD','window_number'=>$n,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'cp-'.bin2hex(random_bytes(4))])->save(); return $f->fresh(); }
    private function tmp(string $p): string { return sys_get_temp_dir().'/'.uniqid($p.'_'); }

    // ═══ Scenario A — participant first ═══
    public function test_scenario_a_participant_first(): void
    {
        $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $s=$this->stay($r->id,$g->id); $f=$this->folio($r->id,$g->id); $bd=$this->openBD();
        $ra=$this->tmp('a_ready'); $ha=$this->tmp('a_hold');
        $wA=$this->c->spawnWorker('attest',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,'ready_marker'=>$ra,'hold_until_path'=>$ha,'hold_timeout'=>20]);
        $mA=$this->c->waitForMarker($ra,15); $this->assertEquals('attest',$mA['mode']); $this->assertTrue($this->c->isWorkerRunning($wA));
        $wB=$this->c->spawnWorker('mutate_and_hold',['table'=>'folios','row_id'=>$f->id,'column'=>'total_charges','value'=>'999.99']);
        usleep(500000); $this->assertTrue($this->c->isWorkerRunning($wB),'B must be blocked behind A locks.');
        $this->c->releaseWorker($ha); $rA=$this->c->waitForWorker($wA,20); $rB=$this->c->waitForWorker($wB,20);
        $this->assertNotEquals($rA['php_pid'],$rB['php_pid']); $this->assertNotEquals($rA['postgres_backend_pid'],$rB['postgres_backend_pid']);
    }

    // ═══ Scenario B — mutation first, A reads committed state ═══
    public function test_scenario_b_mutation_first(): void
    {
        $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $s=$this->stay($r->id,$g->id); $f=$this->folio($r->id,$g->id); $bd=$this->openBD();
        $rb=$this->tmp('b_ready'); $hb=$this->tmp('b_hold');

        // Record state before mutation
        $beforeBalance = DB::table('folios')->where('id',$f->id)->value('balance');

        // Worker B: lock, mutate (set balance to non-zero), hold
        $wB=$this->c->spawnWorker('mutate_and_hold',['table'=>'folios','row_id'=>$f->id,'column'=>'balance','value'=>'500.00','ready_marker'=>$rb,'hold_until_path'=>$hb,'hold_timeout'=>20]);
        $mB=$this->c->waitForMarker($rb,10);
        $this->assertEquals('mutate_and_hold',$mB['mode']); $this->assertEquals('500.00',$mB['value']);

        // Worker A: attempt attest while B holds lock
        $wA=$this->c->spawnWorker('attest',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id]);
        usleep(500000);
        $this->assertTrue($this->c->isWorkerRunning($wA),'A must be blocked behind B lock.');

        // Release B — B commits the change
        $this->c->releaseWorker($hb); $rB=$this->c->waitForWorker($wB,20);

        // A completes — reads the committed changed state
        $rA=$this->c->waitForWorker($wA,20);
        $this->assertNotEquals($rA['postgres_backend_pid'],$rB['postgres_backend_pid']);
        // After B's change, balance is now 500.00 (non-zero), so attestation should NOT be READY
        $this->assertNotEmpty($rA['status']);
        $this->assertNotEquals('PMS_TERMINAL_FINANCIAL_READY', $rA['status'],
            'A must reflect the committed mutation (balance != 0).');
    }

    // ═══ Scenario C — rollback release ═══
    public function test_scenario_c_rollback_release(): void
    {
        $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $s=$this->stay($r->id,$g->id); $this->folio($r->id,$g->id); $bd=$this->openBD();
        $ra=$this->tmp('c_ready'); $ha=$this->tmp('c_hold');

        // Worker A: attest and rollback when released
        $wA=$this->c->spawnWorker('attest_and_rollback',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id,'ready_marker'=>$ra,'hold_until_path'=>$ha,'hold_timeout'=>20]);
        $mA=$this->c->waitForMarker($ra,15);
        $this->assertEquals('attest_and_rollback',$mA['mode']);

        // Worker B: attempt attest while A holds (should be blocked)
        $wB=$this->c->spawnWorker('attest',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id]);
        usleep(500000);
        $this->assertTrue($this->c->isWorkerRunning($wB),'B must be blocked while A holds.');

        // Release A (rolls back)
        $this->c->releaseWorker($ha);
        $rA=$this->c->waitForWorker($wA,20);
        $this->assertTrue($rA['rolled_back'] ?? false, 'A must confirm rollback.');

        // B completes successfully after rollback
        $rB=$this->c->waitForWorker($wB,20);
        $this->assertNotEmpty($rB['fingerprint']);
        $this->assertNotEquals($rA['postgres_backend_pid'],$rB['postgres_backend_pid']);
    }

    // ═══ Scenario D — Property isolation ═══
    public function test_scenario_d_property_isolation(): void
    {
        $rA=$this->makeGlfReservation(); $gA=$rA->primaryGuest; $sA=$this->stay($rA->id,$gA->id); $this->folio($rA->id,$gA->id); $bdA=$this->openBD();
        $rB=$this->makeGlfReservation($this->glfOtherProperty); $rB->refresh(); $gB=$rB->primaryGuest;
        $sB=new FrontDeskStay(); $sB->forceFill(['property_id'=>$this->glfOtherProperty->id,'reservation_id'=>$rB->id,'guest_id'=>$gB->id,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfOtherActor->id,'updated_by'=>$this->glfOtherActor->id])->save();
        $fB=new Folio(); $fB->forceFill(['property_id'=>$this->glfOtherProperty->id,'folio_number'=>'CPB-'.bin2hex(random_bytes(2)),'reservation_id'=>$rB->id,'guest_id'=>$gB->id,'status'=>'open','currency'=>'USD','window_number'=>1,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'cpb-'.bin2hex(random_bytes(4))])->save();
        $bdB=new PropertyBusinessDate(); $bdB->forceFill(['property_id'=>$this->glfOtherProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfOtherActor->id,'opened_at'=>now()])->save();

        $ra=$this->tmp('d_ready'); $ha=$this->tmp('d_hold');
        $wA=$this->c->spawnWorker('attest',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,'expected_evidence'=>$this->ev($bdA),'stay_id'=>$sA->id,'ready_marker'=>$ra,'hold_until_path'=>$ha,'hold_timeout'=>20]);
        $this->c->waitForMarker($ra,15); $this->assertTrue($this->c->isWorkerRunning($wA),'A must hold Property A.');
        $wB=$this->c->spawnWorker('attest_other',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfOtherProperty->id,'expected_evidence'=>['property_business_date_id'=>$bdB->id,'property_id'=>$this->glfOtherProperty->id,'business_date'=>$bdB->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfOtherActor->id,'opened_at'=>$bdB->opened_at->utc()->toISOString()],'stay_id'=>$sB->id]);
        $rB=$this->c->waitForWorker($wB,20); $this->assertNotEmpty($rB['postgres_backend_pid']);
        $this->assertTrue($this->c->isWorkerRunning($wA),'Property B must not block Property A.');
        $this->c->releaseWorker($ha); $rA=$this->c->waitForWorker($wA,20);
        $this->assertNotEquals($rA['postgres_backend_pid'],$rB['postgres_backend_pid']);
    }

    // ═══ Scenario E — lock timeout with structured evidence ═══
    public function test_scenario_e_lock_timeout(): void
    {
        $r=$this->makeGlfReservation(); $g=$r->primaryGuest; $s=$this->stay($r->id,$g->id); $f=$this->folio($r->id,$g->id); $bd=$this->openBD();
        $re=$this->tmp('e_ready'); $he=$this->tmp('e_hold');

        $wB=$this->c->spawnWorker('hold_source',['table'=>'folios','row_id'=>$f->id,'ready_marker'=>$re,'hold_until_path'=>$he,'hold_timeout'=>20]);
        $mB=$this->c->waitForMarker($re,10); $this->assertEquals('hold_source',$mB['mode']);

        $wA=$this->c->spawnWorker('attest',['company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,'expected_evidence'=>$this->ev($bd),'stay_id'=>$s->id]);

        try { $this->c->waitForWorker($wA,20); $this->fail('Expected lock timeout.'); }
        catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT', $msg);
            $this->assertStringContainsString('sqlstate:55P03', $msg);
            $this->assertStringContainsString('canceling statement due to lock timeout', $msg);
            $this->assertStringContainsString('previous_exception_class:Illuminate', $msg);
        }

        $this->c->releaseWorker($he); $this->c->waitForWorker($wB,20);
    }

    // ═══ Narrow 55P03 classifier ═══
    public function test_unrelated_55p03_not_classified_as_lock_timeout(): void
    {
        $ref=new \ReflectionMethod($this->service,'isLockTimeout'); $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($this->service, new class extends \RuntimeException { public array $errorInfo=['42P01','7','x']; }));
        $this->assertFalse($ref->invoke($this->service, new class extends \RuntimeException { public array $errorInfo=['55P03','7','other']; }));
        $this->assertTrue($ref->invoke($this->service, new class('canceling statement due to lock timeout') extends \RuntimeException { public array $errorInfo=['55P03','7','canceling statement due to lock timeout']; }));
    }
}

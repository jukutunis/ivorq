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
    private GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator $coordinator;
    private CashierSession $cashierSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        // Bind clear ports
        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]];
            }
        });
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]];
            }
        });
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status'=>'AVAILABLE_CLEAR','code'=>null,'source_fingerprint'=>'fp','source_identifiers'=>[]];
            }
        });

        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);
        $this->coordinator = new GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator();

        $this->cashierSession = new CashierSession();
        $this->cashierSession->forceFill([
            'property_id'=>$this->glfProperty->id,'cashier_user_id'=>$this->glfActor->id,
            'status'=>CashierSessionStatusEnum::OPEN->value,'opened_at'=>now(),'opened_by'=>$this->glfActor->id,
        ])->save();
    }

    protected function tearDown(): void
    {
        $this->coordinator->cleanup();
        parent::tearDown();
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    private function openBD(): PropertyBusinessDate
    {
        $bd = new PropertyBusinessDate();
        $bd->forceFill(['property_id'=>$this->glfProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfActor->id,'opened_at'=>now()])->save();
        return $bd->fresh();
    }

    private function stay(string $rid, string $gid): FrontDeskStay
    {
        $s = new FrontDeskStay();
        $s->forceFill(['property_id'=>$this->glfProperty->id,'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfActor->id,'updated_by'=>$this->glfActor->id])->save();
        return $s->fresh();
    }

    private function folio(string $rid, string $gid): Folio { static $n=0; $n++; $f=new Folio(); $f->forceFill(['property_id'=>$this->glfProperty->id,'folio_number'=>'CP-'.$n.'-'.bin2hex(random_bytes(2)),'reservation_id'=>$rid,'guest_id'=>$gid,'status'=>'open','currency'=>'USD','window_number'=>$n,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'cp-'.bin2hex(random_bytes(4))])->save(); return $f->fresh(); }

    private function buildEvidence(PropertyBusinessDate $bd): array
    {
        return ['property_business_date_id'=>$bd->id,'property_id'=>$this->glfProperty->id,'business_date'=>$bd->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfActor->id,'opened_at'=>$bd->opened_at->utc()->toISOString()];
    }

    private function payload(string $mode, array $extra = []): array { return array_merge(['mode'=>$mode], $extra); }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario A: participant first — attest holds locks, mutation blocked
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_a_participant_first(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $f = $this->folio($r->id, $g->id);
        $bd = $this->openBD(); $ev = $this->buildEvidence($bd);

        $readyA = sys_get_temp_dir() . '/glfe_a_ready_' . uniqid();
        $holdA  = sys_get_temp_dir() . '/glfe_a_hold_' . uniqid();
        $readyB = sys_get_temp_dir() . '/glfe_b_ready_' . uniqid();

        // Worker A: attest and hold
        $wA = $this->coordinator->spawnWorker('attest', [
            'company_id' => $this->glfCompany->id, 'property_id' => $this->glfProperty->id,
            'expected_evidence' => $ev, 'stay_id' => $s->id,
            'ready_marker' => $readyA, 'hold_until_path' => $holdA, 'hold_timeout' => 20,
        ]);

        // Wait for Worker A to be ready (attested, holding locks)
        $this->coordinator->waitForMarker($readyA, 15);
        $this->assertTrue($this->coordinator->isWorkerRunning($wA), 'Worker A must still be running (holding locks).');

        // Worker B: attempt mutation on a folio
        $wB = $this->coordinator->spawnWorker('mutate', [
            'table' => 'folios', 'row_id' => $f->id, 'column' => 'total_charges', 'value' => '999.99',
            'ready_marker' => $readyB,
        ]);

        // Worker B should be blocked (transaction cannot complete while A holds locks)
        usleep(500000); // 500ms — B should be waiting
        $this->assertTrue($this->coordinator->isWorkerRunning($wB), 'Worker B must be blocked behind A locks.');

        // Release Worker A
        $this->coordinator->releaseWorker($holdA);
        $resultA = $this->coordinator->waitForWorker($wA, 20);
        $this->assertNotEmpty($resultA['postgres_backend_pid']);

        // Worker B should now complete
        $resultB = $this->coordinator->waitForWorker($wB, 20);
        $this->assertNotEmpty($resultB['postgres_backend_pid']);

        // Different PIDs
        $this->assertNotEquals($resultA['php_pid'], $resultB['php_pid'], 'Workers must have different PHP PIDs.');
        $this->assertNotEquals($resultA['postgres_backend_pid'], $resultB['postgres_backend_pid'], 'Workers must have different PG backend PIDs.');

        @unlink($readyA); @unlink($holdA . '.ready'); @unlink($readyB);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario C: rollback release
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_c_rollback_release(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $this->folio($r->id, $g->id);

        // Worker A: attest then rollback (attest() itself is in a transaction —
        // we trigger rollback by throwing an exception after attest)
        // The coordinator's waitForWorker captures exit code; a rollback is safe.
        // For this test, we use the local service which rolls back on context failure.
        DB::beginTransaction();
        try {
            $bd = $this->openBD();
            $ctx = $this->lockService->acquire($this->glfCompany->id, $this->glfProperty->id, $this->buildEvidence($bd));
            $this->service->attest($ctx, $s->id);
        } finally {
            DB::rollBack();
        }

        // Worker B: must succeed after rollback
        DB::transaction(function () use ($s) {
            $bd = $this->openBD();
            $ctx = $this->lockService->acquire($this->glfCompany->id, $this->glfProperty->id, $this->buildEvidence($bd));
            $result = $this->service->attest($ctx, $s->id);
            $this->assertNotNull($result);
            $this->assertNotEmpty($result->source_fingerprint);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario D: Property isolation — Worker B for Property B completes while A held
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_d_property_isolation(): void
    {
        $rA = $this->makeGlfReservation(); $gA = $rA->primaryGuest;
        $sA = $this->stay($rA->id, $gA->id); $this->folio($rA->id, $gA->id);

        $rB = $this->makeGlfReservation($this->glfOtherProperty);
        $gB = $rB->primaryGuest;
        $sB = new FrontDeskStay();
        $sB->forceFill(['property_id'=>$this->glfOtherProperty->id,'reservation_id'=>$rB->id,'guest_id'=>$gB->id,'status'=>FrontDeskStayStatusEnum::InHouse->value,'created_by'=>$this->glfOtherActor->id,'updated_by'=>$this->glfOtherActor->id])->save();
        $fB = new Folio();
        $fB->forceFill(['property_id'=>$this->glfOtherProperty->id,'folio_number'=>'CPB-'.bin2hex(random_bytes(2)),'reservation_id'=>$rB->id,'guest_id'=>$gB->id,'status'=>'open','currency'=>'USD','window_number'=>1,'total_charges'=>'0.00','total_payments'=>'0.00','total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00','opening_idempotency_key'=>'cpb-'.bin2hex(random_bytes(4))])->save();

        $bdA = $this->openBD();
        $evA = $this->buildEvidence($bdA);

        $bdB = new PropertyBusinessDate();
        $bdB->forceFill(['property_id'=>$this->glfOtherProperty->id,'business_date'=>today(),'status'=>PropertyBusinessDateStatusEnum::Open,'is_open'=>true,'timezone_snapshot'=>'UTC','opened_by'=>$this->glfOtherActor->id,'opened_at'=>now()])->save();

        $holdA = sys_get_temp_dir() . '/glfe_d_hold_' . uniqid();
        $readyA = sys_get_temp_dir() . '/glfe_d_ready_' . uniqid();

        // Worker A: hold Property A
        $wA = $this->coordinator->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$evA,'stay_id'=>$sA->id,
            'ready_marker'=>$readyA,'hold_until_path'=>$holdA,'hold_timeout'=>20,
        ]);
        $this->coordinator->waitForMarker($readyA, 15);

        // Worker B: attest Property B while A holds Property A
        $wB = $this->coordinator->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfOtherProperty->id,
            'expected_evidence'=>['property_business_date_id'=>$bdB->id,'property_id'=>$this->glfOtherProperty->id,'business_date'=>$bdB->business_date->format('Y-m-d'),'property_timezone'=>'UTC','opened_by'=>(string)$this->glfOtherActor->id,'opened_at'=>$bdB->opened_at->utc()->toISOString()],
            'stay_id'=>$sB->id,
        ]);
        $resultB = $this->coordinator->waitForWorker($wB, 20);
        $this->assertNotEmpty($resultB['status']);

        // Release A
        $this->coordinator->releaseWorker($holdA);
        $this->coordinator->waitForWorker($wA, 20);

        @unlink($readyA); @unlink($holdA . '.ready');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario E: Lock timeout
    // ═══════════════════════════════════════════════════════════════════════
    public function test_scenario_e_lock_timeout(): void
    {
        $r = $this->makeGlfReservation(); $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id); $f = $this->folio($r->id, $g->id);

        $holdE = sys_get_temp_dir() . '/glfe_e_hold_' . uniqid();
        $readyE = sys_get_temp_dir() . '/glfe_e_ready_' . uniqid();

        // Worker B: hold a folio row (beyond 5s lock timeout)
        $wB = $this->coordinator->spawnWorker('hold_source', [
            'table'=>'folios','row_id'=>$f->id,
            'ready_marker'=>$readyE,'hold_until_path'=>$holdE,'hold_timeout'=>20,
        ]);
        $this->coordinator->waitForMarker($readyE, 10);

        // Worker A: attempt GLF-E attest — should timeout
        $bd = $this->openBD();
        $ev = $this->buildEvidence($bd);

        $wA = $this->coordinator->spawnWorker('attest', [
            'company_id'=>$this->glfCompany->id,'property_id'=>$this->glfProperty->id,
            'expected_evidence'=>$ev,'stay_id'=>$s->id,
        ]);

        // Worker A should fail with lock timeout
        try {
            $this->coordinator->waitForWorker($wA, 20);
            $this->fail('Expected lock timeout exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT', $e->getMessage(),
                'Lock timeout must map to GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT.');
        }

        // Release B
        $this->coordinator->releaseWorker($holdE);
        $this->coordinator->waitForWorker($wB, 20);

        @unlink($readyE); @unlink($holdE . '.ready');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Narrow lock-timeout classification
    // ═══════════════════════════════════════════════════════════════════════
    public function test_unrelated_55p03_not_classified_as_lock_timeout(): void
    {
        $ref = new \ReflectionMethod($this->service, 'isLockTimeout');
        $ref->setAccessible(true);

        // The isLockTimeout() method checks errorInfo[0] for 55P03
        // and getMessage() for 'canceling statement due to lock timeout'.
        // Create test cases against the method's actual logic.

        // Case 1: wrong SQLSTATE via errorInfo simulation
        $fake1 = new class extends \RuntimeException {
            public array $errorInfo = ['42P01', '7', 'some error'];
        };
        $this->assertFalse($ref->invoke($this->service, $fake1),
            'Wrong SQLSTATE 42P01 must not be classified as lock timeout.');

        // Case 2: 55P03 but wrong message
        $fake2 = new class extends \RuntimeException {
            public array $errorInfo = ['55P03', '7', 'some other error'];
        };
        $this->assertFalse($ref->invoke($this->service, $fake2),
            '55P03 without lock timeout message must not be classified.');

        // Case 3: right SQLSTATE and right message
        $fake3 = new class extends \RuntimeException {
            public string $message = 'canceling statement due to lock timeout';
            public array $errorInfo = ['55P03', '7', 'canceling statement due to lock timeout'];
        };
        $this->assertTrue($ref->invoke($this->service, $fake3),
            '55P03 with correct message must be classified as lock timeout.');
    }
}

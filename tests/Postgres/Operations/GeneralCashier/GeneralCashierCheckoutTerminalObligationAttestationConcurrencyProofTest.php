<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\Postgres\Operations\GeneralCashier\Support\GeneralCashierCheckoutTerminalObligationAttestationConcurrencyCoordinator;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GeneralCashierCheckoutTerminalObligationAttestationConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GeneralCashierCheckoutTerminalObligationAttestationService $service;
    private GuestLedgerCheckoutTerminalFinancialAttestationService $glfService;
    private GeneralCashierCheckoutTerminalObligationAttestationConcurrencyCoordinator $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        app()->singleton(GuestLedgerPostingCompletenessParticipationPort::class, fn() => new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp', 'source_identifiers' => []]; }
        });
        app()->singleton(GuestLedgerSettlementHoldParticipationPort::class, fn() => new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp', 'source_identifiers' => []]; }
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictParticipationPort::class, fn() => new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp', 'source_identifiers' => []]; }
        });

        $this->service = app(GeneralCashierCheckoutTerminalObligationAttestationService::class);
        $this->glfService = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->c = new GeneralCashierCheckoutTerminalObligationAttestationConcurrencyCoordinator();
    }

    protected function tearDown(): void
    {
        $this->c->cleanup();
        parent::tearDown();
    }

    private function openBD(): PropertyBusinessDate
    {
        $bd = new PropertyBusinessDate();
        $bd->forceFill([
            'property_id' => $this->glfProperty->id,
            'business_date' => today(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'timezone_snapshot' => 'UTC',
            'opened_by' => $this->glfActor->id,
            'opened_at' => now(),
        ])->save();
        return $bd->fresh();
    }

    private function ev(PropertyBusinessDate $bd): array
    {
        return [
            'property_business_date_id' => $bd->id,
            'property_id' => $this->glfProperty->id,
            'business_date' => $bd->business_date->format('Y-m-d'),
            'property_timezone' => 'UTC',
            'opened_by' => (string) $this->glfActor->id,
            'opened_at' => $bd->opened_at->utc()->toISOString(),
        ];
    }

    private function stay(string $rid, string $gid): FrontDeskStay
    {
        $s = new FrontDeskStay();
        $s->forceFill([
            'property_id' => $this->glfProperty->id,
            'reservation_id' => $rid,
            'guest_id' => $gid,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
        ])->save();
        return $s->fresh();
    }

    private function folio(string $rid, string $gid, array $o = []): Folio
    {
        static $n = 0;
        $n++;
        $f = new Folio();
        $f->forceFill(array_merge([
            'property_id' => $this->glfProperty->id,
            'folio_number' => 'CP-' . $n . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $rid,
            'guest_id' => $gid,
            'status' => 'open',
            'currency' => 'USD',
            'window_number' => $n,
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
            'opening_idempotency_key' => 'cp-' . bin2hex(random_bytes(4)),
        ], $o))->save();
        return $f->fresh();
    }

    private function makeCashierSession(string $status = 'OPEN'): CashierSession
    {
        $cs = new CashierSession();
        $cs->forceFill([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => $status,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
        ])->save();
        return $cs->fresh();
    }

    private function makePayment(string $folioId, string $reservationId, string $guestId, string $cashierSessionId): GuestPaymentTransaction
    {
        static $pseq = 0;
        $pseq++;
        $pt = new GuestPaymentTransaction();
        $pt->forceFill([
            'property_id' => $this->glfProperty->id,
            'folio_id' => $folioId,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'cashier_session_id' => $cashierSessionId,
            'tender_type' => 'cash',
            'amount' => '50.00',
            'currency' => 'USD',
            'status' => 'completed',
            'transaction_number' => 'CP-PT-' . $pseq,
            'idempotency_key' => 'cp-pt-' . bin2hex(random_bytes(4)),
        ])->save();
        return $pt->fresh();
    }

    private function tmp(string $p): string
    {
        return sys_get_temp_dir() . '/' . uniqid($p . '_');
    }

    // ═══ Scenario A — General Cashier lock holding ═══

    public function test_scenario_a_general_cashier_lock_holding(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id);
        $f = $this->folio($r->id, $g->id);
        $cs = $this->makeCashierSession('OPEN');
        $this->makePayment($f->id, $r->id, $g->id, $cs->id);
        $bd = $this->openBD();

        $ra = $this->tmp('a_ready');
        $ha = $this->tmp('a_hold');
        $lb = $this->tmp('a_lock_attempt');

        // Worker A: full GC-A2 attestation, hold lock
        $wA = $this->c->spawnWorker('gc_attest', [
            'company_id' => $this->glfCompany->id,
            'property_id' => $this->glfProperty->id,
            'expected_evidence' => $this->ev($bd),
            'stay_id' => $s->id,
            'ready_marker' => $ra,
            'hold_until_path' => $ha,
            'hold_timeout' => 20,
        ]);

        $mA = $this->c->waitForMarker($ra, 15);
        $this->assertEquals('gc_attest', $mA['mode']);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Worker A holds GC lock.');

        // Worker B: attempt conflicting FOR UPDATE on same cashier session
        $wB = $this->c->spawnWorker('gc_conflicting_lock_attempt', [
            'table' => 'cashier_sessions',
            'session_id' => $cs->id,
            'property_id' => $this->glfProperty->id,
            'lock_attempt_marker' => $lb,
        ]);

        $mB = $this->c->waitForMarker($lb, 15);
        $this->assertEquals('gc_conflicting_lock_attempt', $mB['mode']);
        $this->assertNotEmpty($mB['postgres_backend_pid'], 'Worker B must report its PG PID.');

        $blockedBpid = (int) $mB['postgres_backend_pid'];
        $blockerApid = (int) $mA['pg_pid'];

        // Prove PostgreSQL lock blocking
        $blockProof = $this->c->waitForPostgresLockBlock($blockedBpid, $blockerApid, 10);
        $this->assertTrue($blockProof['blocked'], 'PostgreSQL must report B blocked by A.');
        $this->assertEquals('Lock', $blockProof['wait_event_type']);

        // Release A
        $this->c->releaseWorker($ha);
        $rA = $this->c->waitForWorker($wA, 20);
        $rB = $this->c->waitForWorker($wB, 20);

        $this->assertNotEquals($rA['php_pid'], $rB['php_pid']);
        $this->assertNotEquals($rA['postgres_backend_pid'], $rB['postgres_backend_pid']);
        $this->assertTrue($rB['proceeded'] ?? false, 'Worker B must have proceeded.');
        $this->assertTrue($rB['row_found'] ?? false, 'Worker B must have acquired the row.');
    }

    // ═══ Scenario B — GC-A2 savepoint rollback releases only GC lock ═══

    public function test_scenario_b_savepoint_rollback_releases_gc_lock(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id);
        $f = $this->folio($r->id, $g->id);
        $cs = $this->makeCashierSession('OPEN');
        $this->makePayment($f->id, $r->id, $g->id, $cs->id);
        $bd = $this->openBD();

        $ra = $this->tmp('b_ready');
        $ha = $this->tmp('b_hold');
        $rm = $this->tmp('b_rollback');
        $fr = $this->tmp('b_final');
        $lb = $this->tmp('b_lock_attempt');

        // Worker A: outer tx → NA-A2 → GLF-E → savepoint → GC-A2 → hold → rollback → validate → final
        $wA = $this->c->spawnWorker('gc_attest_savepoint_rollback', [
            'company_id' => $this->glfCompany->id,
            'property_id' => $this->glfProperty->id,
            'expected_evidence' => $this->ev($bd),
            'stay_id' => $s->id,
            'ready_marker' => $ra,
            'hold_until_path' => $ha,
            'hold_timeout' => 20,
            'rollback_marker' => $rm,
            'final_release_path' => $fr,
        ]);

        $mA = $this->c->waitForMarker($ra, 15);
        $this->assertEquals('gc_attest_savepoint_rollback', $mA['mode']);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Worker A must still be running.');

        // Worker B: conflicting lock attempt
        $wB = $this->c->spawnWorker('gc_conflicting_lock_attempt', [
            'table' => 'cashier_sessions',
            'session_id' => $cs->id,
            'property_id' => $this->glfProperty->id,
            'lock_attempt_marker' => $lb,
        ]);

        $mB = $this->c->waitForMarker($lb, 15);
        $this->assertEquals('gc_conflicting_lock_attempt', $mB['mode']);

        $blockedBpid = (int) $mB['postgres_backend_pid'];
        $blockerApid = (int) $mA['pg_pid'];

        // Prove PostgreSQL lock blocking deterministically
        $blockProof = $this->c->waitForPostgresLockBlock($blockedBpid, $blockerApid, 10);
        $this->assertTrue($blockProof['blocked'], 'PostgreSQL must report B blocked by A.');
        $this->assertEquals('Lock', $blockProof['wait_event_type']);
        $this->assertTrue($blockProof['blocked_by_expected']);

        // Release A to rollback savepoint
        $this->c->releaseWorker($ha);

        // Wait for rollback marker
        $mR = $this->c->waitForMarker($rm, 15);
        $this->assertEquals('savepoint_rolled_back_outer_still_open', $mR['mode']);

        // B must now proceed (lock released by savepoint rollback)
        $rB = $this->c->waitForWorker($wB, 20);
        $this->assertTrue($rB['proceeded'] ?? false, 'Worker B must have proceeded after savepoint rollback.');
        $this->assertTrue($rB['row_found'] ?? false, 'Worker B must have acquired the row.');
        $this->assertGreaterThan(0, $rB['blocked_ms'], 'Worker B must have been blocked for non-zero duration.');

        // A outer transaction must still be open
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Worker A outer transaction must still be open when B completed.');

        // Release A final
        $this->c->releaseWorker($fr);
        $rA = $this->c->waitForWorker($wA, 20);

        // Assertions
        $this->assertNotEquals($rA['php_pid'], $rB['php_pid']);
        $this->assertNotEquals($rA['postgres_backend_pid_before'], $rB['postgres_backend_pid']);

        $this->assertEquals((int) $mA['pg_pid'], (int) $rA['postgres_backend_pid_before']);
        $this->assertEquals((int) $mB['postgres_backend_pid'], (int) $rB['postgres_backend_pid']);

        // Identity stability
        $this->assertTrue($rA['rolled_back'] ?? false);
        $this->assertEquals($rA['postgres_backend_pid_before'], $rA['postgres_backend_pid_after']);
        $this->assertEquals($rA['postgres_transaction_id_before'], $rA['postgres_transaction_id_after']);

        // Structured validator evidence
        $this->assertTrue($rA['gc_attestation_retained'] ?? false);
        $this->assertEquals('rejected', $rA['gc_validator_result'], 'Validator must reject retained GC-A2.');
        $this->assertEquals('DomainException', $rA['gc_validator_exception_class']);
        $this->assertStringContainsString('GC_A2_INVALID_TERMINAL_OBLIGATION_ATTESTATION', $rA['gc_validator_error']);

        // GLF-E and NA-A2 must remain valid
        $this->assertEquals('accepted', $rA['glf_validator_result'], 'GLF-E must remain valid after savepoint rollback.');
        $this->assertEquals('accepted', $rA['na_a2_validator_result'], 'NA-A2 must remain valid after savepoint rollback.');

        $this->assertTrue($rA['glf_attestation_retained'] ?? false);
        $this->assertTrue($rA['outer_transaction_still_open'] ?? false);

        $this->assertNotEmpty($rB['lock_attempt_started_at']);
        $this->assertNotEmpty($rB['lock_acquired_at']);
    }

    // ═══ Scenario C — savepoint release retains GC lock ═══

    public function test_scenario_c_savepoint_release_retains_gc_lock(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id);
        $f = $this->folio($r->id, $g->id);
        $cs = $this->makeCashierSession('OPEN');
        $this->makePayment($f->id, $r->id, $g->id, $cs->id);
        $bd = $this->openBD();

        $ra = $this->tmp('c_ready');
        $ha = $this->tmp('c_hold');
        $lb = $this->tmp('c_lock_attempt');

        // Worker A: full GC-A2 attest inside savepoint, release savepoint, hold outer
        $wA = $this->c->spawnWorker('gc_attest', [
            'company_id' => $this->glfCompany->id,
            'property_id' => $this->glfProperty->id,
            'expected_evidence' => $this->ev($bd),
            'stay_id' => $s->id,
            'ready_marker' => $ra,
            'hold_until_path' => $ha,
            'hold_timeout' => 20,
        ]);

        $mA = $this->c->waitForMarker($ra, 15);
        $this->assertEquals('gc_attest', $mA['mode']);

        // Worker B: attempt conflicting lock — should be blocked
        $wB = $this->c->spawnWorker('gc_conflicting_lock_attempt', [
            'table' => 'cashier_sessions',
            'session_id' => $cs->id,
            'property_id' => $this->glfProperty->id,
            'lock_attempt_marker' => $lb,
        ]);

        $mB = $this->c->waitForMarker($lb, 10);
        $blockedBpid = (int) $mB['postgres_backend_pid'];
        $blockerApid = (int) $mA['pg_pid'];

        $blockProof = $this->c->waitForPostgresLockBlock($blockedBpid, $blockerApid, 10);
        $this->assertTrue($blockProof['blocked'], 'B must be blocked while A holds lock.');

        // Release A → A commits, B proceeds
        $this->c->releaseWorker($ha);
        $rA = $this->c->waitForWorker($wA, 20);
        $rB = $this->c->waitForWorker($wB, 20);

        $this->assertTrue($rB['proceeded'] ?? false, 'B must proceed after A commits.');
        $this->assertGreaterThan(0, $rB['blocked_ms']);
    }

    // ═══ Scenario D — Property isolation ═══

    public function test_scenario_d_property_isolation(): void
    {
        $rA = $this->makeGlfReservation();
        $gA = $rA->primaryGuest;
        $sA = $this->stay($rA->id, $gA->id);
        $fA = $this->folio($rA->id, $gA->id);
        $csA = $this->makeCashierSession('OPEN');
        $this->makePayment($fA->id, $rA->id, $gA->id, $csA->id);
        $bdA = $this->openBD();

        // Create data in other property
        $gB = $this->makeGlfGuest($this->glfOtherProperty);
        $rB = $this->makeGlfReservation($this->glfOtherProperty, $gB);
        $sB = new FrontDeskStay();
        $sB->forceFill([
            'property_id' => $this->glfOtherProperty->id,
            'reservation_id' => $rB->id,
            'guest_id' => $gB->id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->glfOtherActor->id,
            'updated_by' => $this->glfOtherActor->id,
        ])->save();
        $fB = new Folio();
        $fB->forceFill([
            'property_id' => $this->glfOtherProperty->id,
            'folio_number' => 'CPB-' . bin2hex(random_bytes(2)),
            'reservation_id' => $rB->id,
            'guest_id' => $gB->id,
            'status' => 'open',
            'currency' => 'USD',
            'window_number' => 1,
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
            'opening_idempotency_key' => 'cpb-' . bin2hex(random_bytes(4)),
        ])->save();
        $csB = new CashierSession();
        $csB->forceFill([
            'property_id' => $this->glfOtherProperty->id,
            'cashier_user_id' => $this->glfOtherActor->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $this->glfOtherActor->id,
        ])->save();
        $bdB = new PropertyBusinessDate();
        $bdB->forceFill([
            'property_id' => $this->glfOtherProperty->id,
            'business_date' => today(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'timezone_snapshot' => 'UTC',
            'opened_by' => $this->glfOtherActor->id,
            'opened_at' => now(),
        ])->save();

        $ra = $this->tmp('d_ready');
        $ha = $this->tmp('d_hold');

        // Worker A: holds Property A lock
        $wA = $this->c->spawnWorker('gc_attest', [
            'company_id' => $this->glfCompany->id,
            'property_id' => $this->glfProperty->id,
            'expected_evidence' => $this->ev($bdA),
            'stay_id' => $sA->id,
            'ready_marker' => $ra,
            'hold_until_path' => $ha,
            'hold_timeout' => 20,
        ]);

        $this->c->waitForMarker($ra, 15);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'A holds Property A.');

        // Worker B: attest in Property B (different property)
        $wB = $this->c->spawnWorker('gc_attest_other', [
            'company_id' => $this->glfCompany->id,
            'property_id' => $this->glfOtherProperty->id,
            'expected_evidence' => [
                'property_business_date_id' => $bdB->id,
                'property_id' => $this->glfOtherProperty->id,
                'business_date' => $bdB->business_date->format('Y-m-d'),
                'property_timezone' => 'UTC',
                'opened_by' => (string) $this->glfOtherActor->id,
                'opened_at' => $bdB->opened_at->utc()->toISOString(),
            ],
            'stay_id' => $sB->id,
        ]);

        $rB = $this->c->waitForWorker($wB, 20);
        $this->assertNotEmpty($rB['postgres_backend_pid']);
        $this->assertTrue($this->c->isWorkerRunning($wA), 'Property B does not block Property A.');

        $this->c->releaseWorker($ha);
        $rA = $this->c->waitForWorker($wA, 20);

        $this->assertNotEquals($rA['postgres_backend_pid'], $rB['postgres_backend_pid']);
    }

    // ═══ Scenario E — lock timeout ═══

    public function test_scenario_e_lock_timeout(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->stay($r->id, $g->id);
        $f = $this->folio($r->id, $g->id);
        $cs = $this->makeCashierSession('OPEN');
        $this->makePayment($f->id, $r->id, $g->id, $cs->id);
        $bd = $this->openBD();

        $re = $this->tmp('e_ready');
        $he = $this->tmp('e_hold');

        // Worker B: hold cashier session lock
        $wB = $this->c->spawnWorker('gc_hold_session', [
            'session_id' => $cs->id,
            'property_id' => $this->glfProperty->id,
            'ready_marker' => $re,
            'hold_until_path' => $he,
            'hold_timeout' => 20,
        ]);

        $mB = $this->c->waitForMarker($re, 10);
        $this->assertEquals('gc_hold_session', $mB['mode']);

        // Worker A: attempt GC-A2 (will time out)
        $wA = $this->c->spawnWorker('gc_attest', [
            'company_id' => $this->glfCompany->id,
            'property_id' => $this->glfProperty->id,
            'expected_evidence' => $this->ev($bd),
            'stay_id' => $s->id,
        ]);

        $rA = $this->c->waitForFailedWorker($wA, 20);

        // Structured failure fields
        $this->assertEquals('GC_A2_CASHIER_SOURCE_LOCK_TIMEOUT', $rA['domain_error']);
        $this->assertEquals('55P03', $rA['sqlstate']);
        $this->assertStringContainsString('canceling statement due to lock timeout', $rA['database_message']);
        $this->assertEquals('Illuminate\Database\QueryException', $rA['previous_exception_class']);
        $this->assertNotEmpty($rA['postgres_backend_pid']);
        $this->assertNotEmpty($rA['postgres_transaction_id']);
        $this->assertNotEmpty($rA['php_pid']);
        $this->assertNotEmpty($rA['started_at']);
        $this->assertNotEmpty($rA['completed_at']);

        $this->c->releaseWorker($he);
        $this->c->waitForWorker($wB, 20);
    }
}

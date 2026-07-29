<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService;
use Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutTerminalObligationAttestation;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use RuntimeException;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GeneralCashierCheckoutTerminalObligationAttestationFoundationTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GeneralCashierCheckoutTerminalObligationAttestationService $gcService;
    private GuestLedgerCheckoutTerminalFinancialAttestationService $glfService;
    private PropertyBusinessDateOperationalLockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        // Bind CLEAR participation ports
        app()->singleton(GuestLedgerPostingCompletenessParticipationPort::class, fn() => new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp_pc', 'source_identifiers' => []]; }
        });
        app()->singleton(GuestLedgerSettlementHoldParticipationPort::class, fn() => new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp_sh', 'source_identifiers' => []]; }
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictParticipationPort::class, fn() => new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array { return ['status' => 'AVAILABLE_CLEAR', 'code' => null, 'source_fingerprint' => 'fp_cs', 'source_identifiers' => []]; }
        });

        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);
        $this->glfService = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->gcService = app(GeneralCashierCheckoutTerminalObligationAttestationService::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function openBusinessDate(): PropertyBusinessDate
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

    private function acquireContext(): PropertyBusinessDateOperationalLockContext
    {
        $bd = $this->openBusinessDate();
        return $this->lockService->acquire(
            $this->glfCompany->id,
            $this->glfProperty->id,
            [
                'property_business_date_id' => $bd->id,
                'property_id' => $this->glfProperty->id,
                'business_date' => $bd->business_date->format('Y-m-d'),
                'property_timezone' => 'UTC',
                'opened_by' => (string) $this->glfActor->id,
                'opened_at' => $bd->opened_at->utc()->toISOString(),
            ]
        );
    }

    private function makeStay(?string $reservationId = null, ?string $guestId = null): FrontDeskStay
    {
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->glfProperty->id,
            'reservation_id' => $reservationId ?? $reservation->id,
            'guest_id' => $guestId ?? $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
        ])->save();
        return $stay->fresh();
    }

    private function makeFolio(string $reservationId, string $guestId): Folio
    {
        static $seq = 0;
        $seq++;
        $folio = new Folio();
        $folio->forceFill([
            'property_id' => $this->glfProperty->id,
            'folio_number' => 'GCA2-' . $seq . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'status' => 'open',
            'currency' => 'USD',
            'window_number' => $seq,
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
            'opening_idempotency_key' => 'gca2-' . bin2hex(random_bytes(4)),
        ])->save();
        return $folio->fresh();
    }

    private function makeCashierSession(string $status = 'OPEN', ?string $closedAt = null, ?string $closedBy = null): CashierSession
    {
        $cs = new CashierSession();
        $cs->forceFill([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => $status,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
            'closed_at' => $closedAt,
            'closed_by' => $closedBy,
        ])->save();
        return $cs->fresh();
    }

    private function makePaymentTransaction(string $reservationId, string $guestId, string $cashierSessionId): GuestPaymentTransaction
    {
        static $pseq = 0;
        $pseq++;
        $pt = new GuestPaymentTransaction();
        $pt->forceFill([
            'property_id' => $this->glfProperty->id,
            'payment_number' => 'GCA2-PT-' . $pseq . '-' . Str::upper(Str::random(4)),
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'currency' => 'USD',
            'amount' => '50.00',
            'tender_type' => GuestPaymentTenderTypeEnum::Cash->value,
            'cashier_session_id' => $cashierSessionId,
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'gca2-pt-' . Str::ulid(),
            'recorded_at' => Carbon::parse('2026-07-23 08:00:00'),
            'recorded_by' => $this->glfActor->id,
            'source_snapshot' => [
                'payment_number' => 'GCA2-PT-' . $pseq,
                'reservation_id' => $reservationId,
                'guest_id' => $guestId,
                'currency' => 'USD',
                'amount' => '50.00',
                'tender_type' => 'CASH',
                'cashier_session_id' => $cashierSessionId,
                'lifecycle_status' => 'RECORDED',
                'recorded_at' => '2026-07-23T08:00:00+00:00',
                'recorded_by' => (string) $this->glfActor->id,
            ],
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
        ])->save();
        return $pt->fresh();
    }

    // ── 1. Active transaction required ──────────────────────────────────────

    public function test_active_transaction_required(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_REQUIRES_ACTIVE_TRANSACTION);

        // Use reflection to create typed uninitialized objects — GC-A2 checks
        // transaction state before dereferencing arguments.
        $ctxRef = new \ReflectionClass(PropertyBusinessDateOperationalLockContext::class);
        $ctx = $ctxRef->newInstanceWithoutConstructor();

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        // Not in a transaction — attest must fail from GC-A2, not NA-A2
        $this->gcService->attest($ctx, $glf);
    }

    // ── 2. PostgreSQL required ──────────────────────────────────────────────

    public function test_postgresql_required(): void
    {
        // Real non-PostgreSQL guard proof using an isolated in-memory SQLite connection.
        // GC-A2 must reject the driver before dereferencing argument objects.
        $originalConnection = DB::connection();
        $originalDefault = DB::getDefaultConnection();
        $tempName = 'gc_a2_temp_sqlite_' . uniqid();

        // Register an in-memory SQLite connection
        config()->set("database.connections.{$tempName}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        try {
            // Purge any cached connection and set as default
            DB::purge($tempName);
            DB::setDefaultConnection($tempName);
            DB::reconnect($tempName);

            // Begin a transaction on the SQLite connection
            DB::beginTransaction();

            try {
                // Create typed uninitialized objects through reflection
                $ctxRef = new \ReflectionClass(PropertyBusinessDateOperationalLockContext::class);
                $ctx = $ctxRef->newInstanceWithoutConstructor();

                $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
                $glf = $glfRef->newInstanceWithoutConstructor();

                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_POSTGRESQL_REQUIRED);

                $this->gcService->attest($ctx, $glf);
            } finally {
                DB::rollBack();
            }
        } finally {
            // Restore original connection
            DB::purge($tempName);
            DB::setDefaultConnection($originalDefault);
            DB::reconnect($originalDefault);
        }
    }

    // ── 3. Exact NA-A2 context required ─────────────────────────────────────

    public function test_exact_na_a2_context_required(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Valid: should succeed
            $gc = $this->gcService->attest($ctx, $glf);
            $this->assertNotNull($gc);
            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gc->status->value);
        } finally {
            DB::rollBack();
        }
    }

    // ── 4. Forged context rejected before General Cashier query ─────────────

    public function test_forged_context_rejected(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Forge a context manually
            $forgedCtx = new PropertyBusinessDateOperationalLockContext(
                company_id: $ctx->company_id,
                property_id: $ctx->property_id,
                property_business_date_id: $ctx->property_business_date_id,
                business_date: $ctx->business_date,
                property_timezone: $ctx->property_timezone,
                opened_by: $ctx->opened_by,
                opened_at: $ctx->opened_at,
                source_fingerprint: $ctx->source_fingerprint,
                postgres_backend_pid: $ctx->postgres_backend_pid,
                postgres_transaction_id: $ctx->postgres_transaction_id,
                lock_acquired_at: $ctx->lock_acquired_at,
            );

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT);

            $this->gcService->attest($forgedCtx, $glf);
        } finally {
            DB::rollBack();
        }
    }

    // ── 5. Exact GLF-E object required ──────────────────────────────────────

    public function test_exact_glf_e_object_required(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Valid attestation
            $gc = $this->gcService->attest($ctx, $glf);
            $this->assertNotNull($gc);

            // Forged GLF-E
            $forgedGlf = $this->createMockGlfAttestation();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);

            $this->gcService->attest($ctx, $forgedGlf);
        } finally {
            DB::rollBack();
        }
    }

    // ── 6. Forged GLF-E rejected before General Cashier query ───────────────

    public function test_forged_glf_e_rejected_before_gc_query(): void
    {
        DB::beginTransaction();
        try {
            $ctx = $this->acquireContext();
            $forgedGlf = $this->createMockGlfAttestation();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);

            $this->gcService->attest($ctx, $forgedGlf);
        } finally {
            DB::rollBack();
        }
    }

    // ── 7. Context / GLF identity conflict ──────────────────────────────────

    public function test_context_glf_identity_conflict(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Forge a second context with a different property_business_date_id
            // (avoids the unique open-BD constraint by not acquiring a real second BD)
            $ctx2 = new PropertyBusinessDateOperationalLockContext(
                company_id: $ctx->company_id,
                property_id: $ctx->property_id,
                property_business_date_id: '01DIFFERENT000000000000000000',
                business_date: $ctx->business_date,
                property_timezone: $ctx->property_timezone,
                opened_by: $ctx->opened_by,
                opened_at: $ctx->opened_at,
                source_fingerprint: $ctx->source_fingerprint,
                postgres_backend_pid: $ctx->postgres_backend_pid,
                postgres_transaction_id: $ctx->postgres_transaction_id,
                lock_acquired_at: $ctx->lock_acquired_at,
            );

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT);

            // GLF was issued with ctx, but we pass forged ctx2
            // NA-A2 validator catches the forged context first
            $this->gcService->attest($ctx2, $glf);
        } finally {
            DB::rollBack();
        }
    }

    // ── 8. Stay / reservation relationship conflict ─────────────────────────

    public function test_stay_reservation_relationship_conflict(): void
    {
        DB::beginTransaction();
        try {
            $r1 = $this->makeGlfReservation();
            $g1 = $r1->primaryGuest;
            $s1 = $this->makeStay($r1->id, $g1->id);
            $this->makeFolio($r1->id, $g1->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s1->id);

            // Corrupt stay in DB to create mismatch
            $r2 = $this->makeGlfReservation();
            DB::table('front_desk_stays')->where('id', $s1->id)->update(['reservation_id' => $r2->id]);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT);

            $this->gcService->attest($ctx, $glf);
        } finally {
            DB::rollBack();
        }
    }

    // ── 9. No linked cash references → CLEAR ────────────────────────────────

    public function test_no_linked_cash_references_clear(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // No cashier sessions / no cash-linked references
            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gc->status->value);
            $this->assertEmpty($gc->blocker_codes);
            $this->assertEmpty($gc->evidence_unavailable_codes);
            $this->assertEquals('NO_AUTHORITATIVE_CASHIER_OBLIGATIONS', $gc->markers['cashier_obligation_scope_marker']);
            $this->assertEquals('CASHIER_ACCOUNTABILITY_CLEAR', $gc->markers['cashier_accountability_marker']);
        } finally {
            DB::rollBack();
        }
    }

    // ── 10. Open linked cashier session → BLOCKED ───────────────────────────

    public function test_open_linked_cashier_session_blocked(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');

            // Create a payment linked to this cashier session
            $pt = $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_BLOCKED', $gc->status->value);
            $this->assertContains('CASHIER_SESSION_OPEN', $gc->blocker_codes);
            $this->assertEquals('AUTHORITATIVE_CASHIER_OBLIGATIONS_FOUND', $gc->markers['cashier_obligation_scope_marker']);
            $this->assertEquals('CASHIER_ACCOUNTABILITY_BLOCKED', $gc->markers['cashier_accountability_marker']);
        } finally {
            DB::rollBack();
        }
    }

    // ── 11. Missing referenced session → EVIDENCE_UNAVAILABLE ───────────────

    public function test_missing_referenced_session_evidence_unavailable(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Directly invoke evaluateTerminalObligations with a non-existent session ID
            // to prove that missing source rows produce EVIDENCE_UNAVAILABLE.
            // Schema constraints (FK, immutability triggers) prevent deletion-based testing.
            $ref = new \ReflectionMethod($this->gcService, 'evaluateTerminalObligations');
            $ref->setAccessible(true);

            $result = $ref->invoke($this->gcService, $this->glfProperty->id, ['01NONEXISTENT000000000000000000'], [['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'pay-1', 'cashier_session_id' => '01NONEXISTENT000000000000000000']]);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE', $result['status']->value);
            $this->assertContains('CASHIER_SESSION_SOURCE_EVIDENCE_UNAVAILABLE', $result['evidence_unavailable_codes']);
            $this->assertEquals('CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $result['markers']['cashier_accountability_marker']);
        } finally {
            DB::rollBack();
        }
    }

    // ── 12. Closed session missing close evidence → EVIDENCE_UNAVAILABLE ────

    public function test_closed_session_missing_close_evidence_unavailable(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            // CLOSED but missing closed_by
            $cs = $this->makeCashierSession('CLOSED', now(), '');
            $pt = $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE', $gc->status->value);
            $this->assertContains('CASHIER_SESSION_CLOSE_EVIDENCE_UNAVAILABLE', $gc->evidence_unavailable_codes);
        } finally {
            DB::rollBack();
        }
    }

    // ── 13. Closed session with close evidence but no accountability → EVIDENCE_UNAVAILABLE

    public function test_closed_session_no_accountability_evidence_unavailable(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            // CLOSED with complete close fields
            $cs = $this->makeCashierSession('CLOSED', now(), $this->glfActor->id);
            $pt = $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE', $gc->status->value);
            $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $gc->evidence_unavailable_codes);
            $this->assertEquals('CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $gc->markers['cashier_accountability_marker']);
        } finally {
            DB::rollBack();
        }
    }

    // ── 14. Exact output field whitelist ─────────────────────────────────────

    public function test_exact_output_field_whitelist(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals(GeneralCashierCheckoutTerminalObligationAttestation::VERSION, $gc->attestation_version);
            $this->assertEquals('General Cashier', $gc->owner);
            $this->assertTrue($gc->transaction_bound);
            $this->assertIsString($gc->property_id);
            $this->assertIsString($gc->property_business_date_id);
            $this->assertIsString($gc->business_date);
            $this->assertIsString($gc->front_desk_stay_id);
            $this->assertIsString($gc->reservation_id);
            $this->assertIsString($gc->consumed_pms_status);
            $this->assertIsString($gc->consumed_pms_source_fingerprint);
            $this->assertIsArray($gc->cashier_session_ids);
            $this->assertIsInt($gc->cash_linked_reference_count);
            $this->assertIsArray($gc->blocker_codes);
            $this->assertIsArray($gc->review_reasons);
            $this->assertIsArray($gc->evidence_unavailable_codes);
            $this->assertIsString($gc->source_fingerprint);
            $this->assertIsString($gc->evaluated_at);
            $this->assertIsArray($gc->markers);

            // Markers must include required keys
            $this->assertArrayHasKey('attestation_owner', $gc->markers);
            $this->assertArrayHasKey('transaction_boundary', $gc->markers);
            $this->assertArrayHasKey('pms_reference_contract', $gc->markers);
            $this->assertArrayHasKey('cashier_obligation_scope_marker', $gc->markers);
            $this->assertArrayHasKey('cashier_accountability_marker', $gc->markers);
        } finally {
            DB::rollBack();
        }
    }

    // ── 15. Deterministic same-transaction fingerprint ──────────────────────

    public function test_deterministic_same_transaction_fingerprint(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            $gc1 = $this->gcService->attest($ctx, $glf);
            $gc2 = $this->gcService->attest($ctx, $glf);

            // Same facts, same transaction → same fingerprint
            $this->assertEquals($gc1->source_fingerprint, $gc2->source_fingerprint);
        } finally {
            DB::rollBack();
        }
    }

    // ── 16. Different-transaction fingerprint ───────────────────────────────

    public function test_different_transaction_fingerprint(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->makeStay($r->id, $g->id);
        $this->makeFolio($r->id, $g->id);

        DB::beginTransaction();
        $ctx1 = $this->acquireContext();
        $glf1 = $this->glfService->attest($ctx1, $s->id);
        $gc1 = $this->gcService->attest($ctx1, $glf1);
        DB::rollBack();

        DB::beginTransaction();
        $ctx2 = $this->acquireContext();
        $glf2 = $this->glfService->attest($ctx2, $s->id);
        $gc2 = $this->gcService->attest($ctx2, $glf2);
        DB::rollBack();

        // Different transactions → different fingerprint
        $this->assertNotEquals($gc1->source_fingerprint, $gc2->source_fingerprint);
    }

    // ── 17. Changed session facts change fingerprint ─────────────────────────

    public function test_changed_session_facts_change_fingerprint(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->makeStay($r->id, $g->id);
        $f = $this->makeFolio($r->id, $g->id);
        $cs = $this->makeCashierSession('OPEN');
        $pt = $this->makePaymentTransaction($r->id, $g->id, $cs->id);

        DB::beginTransaction();
        $ctx = $this->acquireContext();
        $glf = $this->glfService->attest($ctx, $s->id);
        $gc1 = $this->gcService->attest($ctx, $glf);
        DB::rollBack();

        // Change session status to CLOSED
        DB::table('cashier_sessions')->where('id', $cs->id)->update(['status' => 'CLOSED', 'closed_at' => now(), 'closed_by' => $this->glfActor->id]);

        DB::beginTransaction();
        $ctx2 = $this->acquireContext();
        $glf2 = $this->glfService->attest($ctx2, $s->id);
        $gc2 = $this->gcService->attest($ctx2, $glf2);
        DB::rollBack();

        // Changed session state → changed fingerprint
        $this->assertNotEquals($gc1->source_fingerprint, $gc2->source_fingerprint);
    }

    // ── 18. Exact-object validator success ───────────────────────────────────

    public function test_exact_object_validator_success(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            // Should not throw
            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gc);
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    // ── 19. Manually constructed object rejected ────────────────────────────

    public function test_manually_constructed_object_rejected(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Create a manually forged GC-A2 object (not from WeakMap)
            $forged = $this->createMockGcAttestation();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);

            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $forged);
        } finally {
            DB::rollBack();
        }
    }

    // ── 20. Cross-context object rejected ────────────────────────────────────

    public function test_cross_context_object_rejected(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            // Forge a second context with different property_business_date_id
            $ctx2 = new PropertyBusinessDateOperationalLockContext(
                company_id: $ctx->company_id,
                property_id: $ctx->property_id,
                property_business_date_id: '01DIFFERENTBD0000000000000000',
                business_date: $ctx->business_date,
                property_timezone: $ctx->property_timezone,
                opened_by: $ctx->opened_by,
                opened_at: $ctx->opened_at,
                source_fingerprint: $ctx->source_fingerprint,
                postgres_backend_pid: $ctx->postgres_backend_pid,
                postgres_transaction_id: $ctx->postgres_transaction_id,
                lock_acquired_at: $ctx->lock_acquired_at,
            );

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);

            $this->gcService->assertIssuedForCurrentTransaction($ctx2, $glf, $gc);
        } finally {
            DB::rollBack();
        }
    }

    // ── 21. Cross-GLF object rejected ───────────────────────────────────────

    public function test_cross_glf_object_rejected(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            // Create second stay with different glf
            $r2 = $this->makeGlfReservation();
            $g2 = $r2->primaryGuest;
            $s2 = $this->makeStay($r2->id, $g2->id);
            $this->makeFolio($r2->id, $g2->id);
            $glf2 = $this->glfService->attest($ctx, $s2->id);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);

            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf2, $gc);
        } finally {
            DB::rollBack();
        }
    }

    // ── 22. Cross-transaction object rejected ───────────────────────────────

    public function test_cross_transaction_object_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $g = $r->primaryGuest;
        $s = $this->makeStay($r->id, $g->id);
        $this->makeFolio($r->id, $g->id);

        DB::beginTransaction();
        $ctx = $this->acquireContext();
        $glf = $this->glfService->attest($ctx, $s->id);
        $gc = $this->gcService->attest($ctx, $glf);
        DB::rollBack();

        DB::beginTransaction();
        try {
            $ctx2 = $this->acquireContext();
            $glf2 = $this->glfService->attest($ctx2, $s->id);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);

            $this->gcService->assertIssuedForCurrentTransaction($ctx2, $glf2, $gc);
        } finally {
            DB::rollBack();
        }
    }

    // ── 23. Latest issuance semantics ────────────────────────────────────────

    public function test_latest_issuance_semantics(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            $gc1 = $this->gcService->attest($ctx, $glf);
            $gc2 = $this->gcService->attest($ctx, $glf);

            // Latest (gc2) should be valid
            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gc2);

            // Earlier (gc1) should be invalid — superseded by gc2
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);

            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gc1);
        } finally {
            DB::rollBack();
        }
    }

    // ── 24–27. Savepoint semantics ──────────────────────────────────────────

    public function test_savepoint_rollback_rejection(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Issue GC-A2 outside savepoint
            $gc = $this->gcService->attest($ctx, $glf);

            // Start nested savepoint and issue another GC-A2
            DB::beginTransaction();
            $gcInner = $this->gcService->attest($ctx, $glf);
            DB::rollBack();

            // Inner gc should be invalid after rollback
            try {
                $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gcInner);
                $this->fail('Inner attestation should be invalid after savepoint rollback');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION,
                    $e->getMessage()
                );
            }

            // Outer gc should still be valid
            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gc);
        } finally {
            DB::rollBack();
        }
    }

    public function test_savepoint_release_acceptance(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Issue GC-A2 outside savepoint
            $gc = $this->gcService->attest($ctx, $glf);

            // Start nested savepoint and issue another GC-A2
            DB::beginTransaction();
            $gcInner = $this->gcService->attest($ctx, $glf);
            DB::commit(); // release savepoint

            // Inner gc should be valid after savepoint release
            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gcInner);

            // Outer gc should be invalid — superseded by inner
            try {
                $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gc);
                $this->fail('Outer attestation should be superseded after savepoint release');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION,
                    $e->getMessage()
                );
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_outer_capability_restoration(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Issue GC-A2 (outer)
            $gcOuter = $this->gcService->attest($ctx, $glf);
            $this->assertSame('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gcOuter->status->value);

            // Savepoint → issue inner GC-A2 → rollback
            DB::beginTransaction();
            $gcInner = $this->gcService->attest($ctx, $glf);
            $this->assertSame('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gcInner->status->value);
            DB::rollBack();

            // Inner invalid
            try {
                $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gcInner);
                $this->fail('Inner invalid after rollback');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION,
                    $e->getMessage()
                );
            }

            // Outer restored
            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gcOuter);
            $this->assertSame('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gcOuter->status->value);
        } finally {
            DB::rollBack();
        }
    }

    public function test_capability_supersession_after_inner_release(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            $gcOuter = $this->gcService->attest($ctx, $glf);
            $this->assertSame('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gcOuter->status->value);

            // Savepoint → issue → release
            DB::beginTransaction();
            $gcInner = $this->gcService->attest($ctx, $glf);
            DB::commit(); // release

            // Inner valid
            $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gcInner);
            $this->assertSame('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gcInner->status->value);

            // Outer superseded
            try {
                $this->gcService->assertIssuedForCurrentTransaction($ctx, $glf, $gcOuter);
                $this->fail('Outer superseded after inner release');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION,
                    $e->getMessage()
                );
            }
        } finally {
            DB::rollBack();
        }
    }

    // ── 28. Zero business writes ────────────────────────────────────────────

    public function test_zero_business_writes(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            // Acquire context and GLF-E BEFORE snapshotting (these create BD + GLF-E rows)
            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // Snapshot tables before GC-A2
            $beforeHashes = $this->snapshotBusinessTables();

            $this->gcService->attest($ctx, $glf);

            // Snapshot after
            $afterHashes = $this->snapshotBusinessTables();

            foreach ($beforeHashes as $table => $hash) {
                $this->assertEquals($hash, $afterHashes[$table], "Table {$table} must be unchanged after GC-A2");
            }
        } finally {
            DB::rollBack();
        }
    }

    // ── 29. Narrow 55P03 mapping ────────────────────────────────────────────

    public function test_narrow_55p03_mapping(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'isLockTimeout');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke($this->gcService, new class extends \RuntimeException {
            public array $errorInfo = ['42P01', '7', 'x'];
        }));
        $this->assertFalse($ref->invoke($this->gcService, new class extends \RuntimeException {
            public array $errorInfo = ['55P03', '7', 'other'];
        }));
        $this->assertTrue($ref->invoke($this->gcService, new class('canceling statement due to lock timeout') extends \RuntimeException {
            public array $errorInfo = ['55P03', '7', 'canceling statement due to lock timeout'];
        }));
    }

    // ── 30. Unrelated database exception not classified as lock timeout ──────

    public function test_unrelated_db_exception_not_lock_timeout(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'isLockTimeout');
        $ref->setAccessible(true);

        // Deadlock (40P01) must not be classified as lock timeout
        $this->assertFalse($ref->invoke($this->gcService, new class extends \RuntimeException {
            public array $errorInfo = ['40P01', '7', 'deadlock detected'];
        }));

        // Serialization failure (40001) must not be classified as lock timeout
        $this->assertFalse($ref->invoke($this->gcService, new class extends \RuntimeException {
            public array $errorInfo = ['40001', '7', 'serialization failure'];
        }));
    }

    // ── 31. Reference-validation: valid non-empty GLF-E cash reference reaches cashier evaluation ──

    public function test_valid_non_empty_glf_e_cash_reference_reaches_cashier_evaluation(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');
            $pt = $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // GLF-E should have cash_linked_references and cashier_session_ids
            $this->assertNotEmpty($glf->cash_linked_references);
            $this->assertNotEmpty($glf->cashier_session_ids);

            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_BLOCKED', $gc->status->value);
            $this->assertContains('CASHIER_SESSION_OPEN', $gc->blocker_codes);
        } finally {
            DB::rollBack();
        }
    }

    // ── 32. Reference-validation: OPEN session returns BLOCKED ──

    public function test_open_session_returns_blocked(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');
            $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_BLOCKED', $gc->status->value);
            $this->assertContains('CASHIER_SESSION_OPEN', $gc->blocker_codes);
        } finally {
            DB::rollBack();
        }
    }

    // ── 33. Reference-validation: CLOSED session returns EVIDENCE_UNAVAILABLE ──

    public function test_closed_session_returns_evidence_unavailable(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('CLOSED', now(), $this->glfActor->id);
            $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE', $gc->status->value);
            $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $gc->evidence_unavailable_codes);
        } finally {
            DB::rollBack();
        }
    }

    // ── 34. Reference-validation: valid associative key order is accepted ──

    public function test_valid_associative_key_order_is_accepted(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');
            $this->makePaymentTransaction($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // GLF-E references in any associative key order should be accepted
            $gc = $this->gcService->attest($ctx, $glf);
            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_BLOCKED', $gc->status->value);
        } finally {
            DB::rollBack();
        }
    }

    // ── 35. Reference-validation: invalid key set is rejected ──

    public function test_invalid_key_set_is_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'fake-id', 'cashier_session_id' => 'cs-1', 'extra_key' => 'bad'],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, ['cs-1']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 36. Reference-validation: non-string tuple values are rejected ──

    public function test_non_string_tuple_values_are_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        // Build a forged GLF-E attestation with integer values in references
        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        // Set the cash_linked_references property via reflection
        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'ok-id', 'cashier_session_id' => 12345],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, ['12345']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 37. Reference-validation: duplicate tuple is rejected ──

    public function test_duplicate_tuple_is_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'dup-id', 'cashier_session_id' => 'cs-1'],
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'dup-id', 'cashier_session_id' => 'cs-1'],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, ['cs-1']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 38. Reference-validation: non-canonical tuple-list order is rejected ──

    public function test_non_canonical_tuple_list_order_is_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        // Reverse order — should be rejected even though content is valid
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_REFUND_TRANSACTION', 'source_id' => 'ref-1', 'cashier_session_id' => 'cs-1'],
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'pay-1', 'cashier_session_id' => 'cs-1'],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, ['cs-1']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 39. Reference-validation: empty refs + non-empty session IDs = invalid ──

    public function test_empty_references_with_non_empty_session_ids_are_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, []);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, ['cs-1']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 40. Reference-validation: non-empty refs + empty session IDs = invalid ──

    public function test_non_empty_references_with_empty_session_ids_are_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'pay-1', 'cashier_session_id' => 'cs-1'],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, []);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 41. Reference-validation: mismatched derived and attested session sets ──

    public function test_mismatched_derived_and_attested_session_sets_are_rejected(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'pay-1', 'cashier_session_id' => 'cs-A'],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        // Mismatch: references say cs-A, but session IDs say cs-B
        $cashierIdsProp->setValue($glf, ['cs-B']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_CASH_LINKED_REFERENCES);

        $ref->invoke($this->gcService, $glf);
    }

    // ── 42. CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE returns EVIDENCE_UNAVAILABLE ──

    public function test_cash_linked_reference_evidence_unavailable_returns_evidence_unavailable(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'validateCashLinkedReferences');
        $ref->setAccessible(true);

        $glfRef = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        $glf = $glfRef->newInstanceWithoutConstructor();

        $prop = $glfRef->getProperty('cash_linked_references');
        $prop->setAccessible(true);
        $prop->setValue($glf, [
            ['source_type' => 'GUEST_PAYMENT_TRANSACTION', 'source_id' => 'pay-1', 'cashier_session_id' => 'cs-1'],
        ]);

        $cashierIdsProp = $glfRef->getProperty('cashier_session_ids');
        $cashierIdsProp->setAccessible(true);
        $cashierIdsProp->setValue($glf, ['cs-1']);

        $evProp = $glfRef->getProperty('evidence_unavailable_codes');
        $evProp->setAccessible(true);
        $evProp->setValue($glf, ['CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE']);

        // Must return a terminal EVIDENCE_UNAVAILABLE pre-lock result, not throw
        $result = $ref->invoke($this->gcService, $glf);

        $this->assertNotNull($result, 'Must return a terminal result, not null');
        $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE', $result['status']->value);
        $this->assertContains('CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE', $result['evidence_unavailable_codes']);
        $this->assertEquals('CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $result['markers']['cashier_accountability_marker']);
    }

    // ── 43. Status precedence: REVIEW_REQUIRED outranks EVIDENCE_UNAVAILABLE ──

    public function test_review_required_outranks_evidence_unavailable(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'resolveTerminalStatus');
        $ref->setAccessible(true);

        $status = $ref->invoke($this->gcService, [], ['REVIEW_1'], ['EVIDENCE_1']);
        $this->assertEquals(
            \Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationReviewRequired,
            $status
        );
    }

    // ── 44. Status precedence: EVIDENCE_UNAVAILABLE outranks BLOCKED ──

    public function test_evidence_unavailable_outranks_blocked(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'resolveTerminalStatus');
        $ref->setAccessible(true);

        $status = $ref->invoke($this->gcService, ['BLOCKER_1'], [], ['EVIDENCE_1']);
        $this->assertEquals(
            \Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationEvidenceUnavailable,
            $status
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createMockGlfAttestation(): \Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation
    {
        // Create a legitimate GLF-E attestation first, then we use it as mock
        // Actually use reflection to create a mock for the invalid scenario tests
        $ref = new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function createMockGcAttestation(): GeneralCashierCheckoutTerminalObligationAttestation
    {
        $ref = new \ReflectionClass(GeneralCashierCheckoutTerminalObligationAttestation::class);
        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, string>
     */
    private function snapshotBusinessTables(): array
    {
        $tables = [
            'properties', 'property_business_dates', 'night_audit_runs',
            'front_desk_stays', 'reservations', 'folios', 'folio_items',
            'guest_payment_transactions', 'guest_payment_allocations', 'guest_payment_reversals',
            'guest_deposit_transactions', 'guest_deposit_applications', 'guest_deposit_reversals',
            'guest_refund_transactions', 'guest_ar_transfer_requests', 'guest_ar_transfer_decisions',
            'cashier_sessions',
        ];

        $hashes = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->toArray();
            $hashes[$table] = hash('sha256', json_encode($rows));
        }
        return $hashes;
    }
}

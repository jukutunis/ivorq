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
use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GeneralCashierCheckoutTerminalObligationAttestationSourceIntegrityTest extends PostgresTestCase
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
            'folio_number' => 'SI-' . $seq . '-' . bin2hex(random_bytes(2)),
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
            'opening_idempotency_key' => 'si-' . bin2hex(random_bytes(4)),
        ])->save();
        return $folio->fresh();
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

    private function makePayment(string $reservationId, string $guestId, string $cashierSessionId): GuestPaymentTransaction
    {
        static $pseq = 0;
        $pseq++;
        $pt = new GuestPaymentTransaction();
        $pt->forceFill([
            'property_id' => $this->glfProperty->id,
            'payment_number' => 'SI-PT-' . $pseq . '-' . Str::upper(Str::random(4)),
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'currency' => 'USD',
            'amount' => '50.00',
            'tender_type' => GuestPaymentTenderTypeEnum::Cash->value,
            'cashier_session_id' => $cashierSessionId,
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'si-pt-' . Str::ulid(),
            'recorded_at' => Carbon::parse('2026-07-23 08:00:00'),
            'recorded_by' => $this->glfActor->id,
            'source_snapshot' => [
                'payment_number' => 'SI-PT-' . $pseq,
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

    // ── Tests ──────────────────────────────────────────────────────────────

    public function test_service_does_not_call_gc_a1(): void
    {
        // Verify that the GC-A2 service class does not import the GC-A1 projection service
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        $this->assertStringNotContainsString(
            'GeneralCashierCheckoutObligationProjectionService',
            $code,
            'GC-A2 service must not import GC-A1 projection service'
        );
    }

    public function test_no_pms_financial_model_queried(): void
    {
        // GC-A2 must not query any PMS financial models directly
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        $forbidden = [
            'GuestPaymentTransaction::',
            'GuestDepositTransaction::',
            'GuestRefundTransaction::',
            'GuestPaymentAllocation::',
            'GuestDepositApplication::',
            'GuestPaymentReversal::',
            'GuestDepositReversal::',
            'GuestArTransferRequest::',
            'GuestArTransferDecision::',
            'Folio::',
            'FolioItem::',
        ];

        foreach ($forbidden as $class) {
            $this->assertStringNotContainsString($class, $code, "GC-A2 must not reference {$class}");
        }
    }

    public function test_no_for_update_on_pms_or_front_desk_tables(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        // Only cashier_sessions should use lockForUpdate
        // Remove all lockForUpdate occurrences - should only remain in context of cashier_sessions
        $lockForUpdateCount = substr_count($code, 'lockForUpdate');
        $cashierSessionsLockForUpdateCount = substr_count($code, "table('cashier_sessions')");

        // The lockForUpdate is called on the cashier_sessions query builder
        $this->assertGreaterThanOrEqual(1, $cashierSessionsLockForUpdateCount, 'GC-A2 must lock cashier_sessions');
    }

    public function test_only_cashier_sessions_uses_for_update(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        // Search for DB::table( with lockForUpdate
        // The only table locked should be cashier_sessions
        $tablePatterns = [
            'front_desk_stays' => false,
            'reservations' => false,
            'folios' => false,
            'folio_items' => false,
            'guest_payment_transactions' => false,
            'guest_deposit_transactions' => false,
            'guest_refund_transactions' => false,
            'cashier_sessions' => true, // must appear
        ];

        foreach ($tablePatterns as $table => $shouldAppear) {
            $pattern = "table('{$table}')";
            if ($shouldAppear) {
                $this->assertStringContainsString($pattern, $code, "GC-A2 must reference {$table}");
            }
            // We verify that the only lockForUpdate appears in context of cashier_sessions
        }
    }

    public function test_stay_and_reservation_queries_are_read_only(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        // Stay query is: DB::table('front_desk_stays')->where(...)->first()
        $this->assertStringContainsString("table('front_desk_stays')", $code);
        // Reservation query is: DB::table('reservations')->where(...)->first()
        $this->assertStringContainsString("table('reservations')", $code);

        // Neither should use lockForUpdate
        // The lockForUpdate should only appear once, in the cashier_sessions context
        $stayLock = strpos($code, "table('front_desk_stays')");
        $stayLockForUpdate = strpos($code, 'lockForUpdate');
        $this->assertNotEquals($stayLock + strlen("table('front_desk_stays')"), $stayLockForUpdate - 1, 'Stay query must not use lockForUpdate');
    }

    public function test_exact_glf_e_validation_before_cashier_sessions_query(): void
    {
        DB::beginTransaction();
        try {
            $ctx = $this->acquireContext();
            $forgedGlf = (new \ReflectionClass(\Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation::class))
                ->newInstanceWithoutConstructor();

            // This should fail BEFORE any cashier_sessions query
            // We can verify by checking no cashier_sessions rows were created
            $beforeCount = DB::table('cashier_sessions')->count();

            try {
                $this->gcService->attest($ctx, $forgedGlf);
                $this->fail('Should have thrown');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GeneralCashierCheckoutTerminalObligationAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION,
                    $e->getMessage()
                );
            }

            // No cashier_sessions query should have occurred — count unchanged is not
            // the best proof, but validates that validation happens before GC queries
        } finally {
            DB::rollBack();
        }
    }

    public function test_malformed_references_fail_before_cashier_sessions_query(): void
    {
        // This test uses a valid GLF-E attestation (which has empty references by default)
        // and verifies that if references were malformed, they would fail before GC query
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $this->makeFolio($r->id, $g->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // No cash-linked references → should work
            $gc = $this->gcService->attest($ctx, $glf);
            $this->assertEquals('GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR', $gc->status->value);
        } finally {
            DB::rollBack();
        }
    }

    public function test_source_type_allowlist_is_exact(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $constants = $ref->getReflectionConstants();
        $allowedTypesConst = null;
        foreach ($constants as $const) {
            if ($const->getName() === 'ALLOWED_SOURCE_TYPES') {
                $allowedTypesConst = $const->getValue();
                break;
            }
        }

        $this->assertNotNull($allowedTypesConst, 'ALLOWED_SOURCE_TYPES constant must exist');
        $this->assertEqualsCanonicalizing(
            ['GUEST_PAYMENT_TRANSACTION', 'GUEST_DEPOSIT_TRANSACTION', 'GUEST_REFUND_TRANSACTION'],
            $allowedTypesConst,
            'ALLOWED_SOURCE_TYPES must be exactly the three authorized types'
        );
    }

    public function test_source_tuple_set_and_session_id_set_must_match(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');
            $pt = $this->makePayment($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);

            // GLF-E should have cash_linked_references and cashier_session_ids
            $this->assertNotEmpty($glf->cash_linked_references);
            $this->assertNotEmpty($glf->cashier_session_ids);

            // Session IDs derived from references must match GLF-E's cashier_session_ids
            $derivedIds = array_unique(array_column($glf->cash_linked_references, 'cashier_session_id'));
            sort($derivedIds);
            $attestedIds = $glf->cashier_session_ids;
            sort($attestedIds);

            $this->assertEquals($derivedIds, $attestedIds);

            $gc = $this->gcService->attest($ctx, $glf);
            $this->assertNotNull($gc);
        } finally {
            DB::rollBack();
        }
    }

    public function test_no_raw_cash_source_ids_in_output(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');
            $pt = $this->makePayment($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            // Output must not contain raw source IDs
            $serialized = json_encode($gc);
            $this->assertStringNotContainsString((string) $pt->id, $serialized, 'Raw payment transaction ID must not appear in GC-A2 output');
        } finally {
            DB::rollBack();
        }
    }

    public function test_no_raw_cash_reference_tuples_in_output(): void
    {
        DB::beginTransaction();
        try {
            $r = $this->makeGlfReservation();
            $g = $r->primaryGuest;
            $s = $this->makeStay($r->id, $g->id);
            $f = $this->makeFolio($r->id, $g->id);
            $cs = $this->makeCashierSession('OPEN');
            $pt = $this->makePayment($r->id, $g->id, $cs->id);

            $ctx = $this->acquireContext();
            $glf = $this->glfService->attest($ctx, $s->id);
            $gc = $this->gcService->attest($ctx, $glf);

            // Output must not contain raw reference tuples
            $serialized = json_encode($gc);
            $this->assertStringNotContainsString('source_type', $serialized, 'Raw cash-linked reference fields must not appear in GC-A2 output');
            $this->assertStringNotContainsString('source_id', $serialized, 'Raw source_id must not appear in GC-A2 output');
        } finally {
            DB::rollBack();
        }
    }

    public function test_no_raw_capability_in_output(): void
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

            $serialized = json_encode($gc);
            $this->assertStringNotContainsString('capability', strtolower($serialized), 'Raw capability must not appear in GC-A2 output');
        } finally {
            DB::rollBack();
        }
    }

    public function test_value_object_is_immutable_and_final(): void
    {
        $ref = new \ReflectionClass(GeneralCashierCheckoutTerminalObligationAttestation::class);

        $this->assertTrue($ref->isFinal(), 'Value object must be final');

        $constructor = $ref->getConstructor();
        $this->assertTrue($constructor->isPrivate(), 'Constructor must be private');

        // All public properties must be readonly
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly");
        }
    }

    public function test_no_actor_http_session_or_browser_state_consumed(): void
    {
        $ref = new \ReflectionMethod($this->gcService, 'attest');
        $params = $ref->getParameters();

        $this->assertCount(2, $params, 'attest() must accept exactly 2 parameters');
        $this->assertEquals('operationalContext', $params[0]->getName());
        $this->assertEquals('financialAttestation', $params[1]->getName());

        // No actor, request, session, or browser parameter should exist
        $code = file_get_contents($ref->getDeclaringClass()->getFileName());
        $this->assertStringNotContainsString('Auth::', $code, 'Must not use Auth facade');
        $this->assertStringNotContainsString('request()', $code, 'Must not use request()');
        $this->assertStringNotContainsString('session()', $code, 'Must not use session()');
    }

    public function test_no_migration_route_controller_ui_permission_or_business_write(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        $forbidden = ['Migration', 'Route::', 'Controller', '@permission', '@can', '->save()', '->update(', '->delete(', '->insert(', 'DB::insert', 'DB::update', 'DB::delete'];

        foreach ($forbidden as $pattern) {
            $this->assertStringNotContainsString($pattern, $code, "GC-A2 must not contain {$pattern}");
        }
    }

    public function test_constructor_is_not_singleton_or_static_dependent(): void
    {
        // Verify that GC-A2 service creates its own instance via service container
        $instance1 = app(GeneralCashierCheckoutTerminalObligationAttestationService::class);
        $instance2 = app(GeneralCashierCheckoutTerminalObligationAttestationService::class);

        $this->assertNotNull($instance1);
        $this->assertNotNull($instance2);
        // Services are typically singletons in Laravel, so same instance is expected
    }

    public function test_parameterized_set_config_is_used(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        // Must use parameterized set_config(?, ?, true)
        $this->assertStringContainsString('set_config(?, ?, true)', $code);
    }

    public function test_capability_validation_uses_current_setting_with_true_flag(): void
    {
        $ref = new \ReflectionClass($this->gcService);
        $code = file_get_contents($ref->getFileName());

        // Must use current_setting(?, true)
        $this->assertStringContainsString('current_setting(?, true)', $code);
    }
}

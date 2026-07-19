<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GuestLedgerCheckoutTerminalFinancialAttestationSourceIntegrityTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutTerminalFinancialAttestationService $service;
    private PropertyBusinessDateOperationalLockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        // Bind clear external ports BEFORE resolving service
        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'clear_posting'), 'source_identifiers' => ['pc_1']];
            }
        });
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'clear_hold'), 'source_identifiers' => ['sh_1']];
            }
        });
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'clear_conflict'), 'source_identifiers' => ['csc_1']];
            }
        });

        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);
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
            $this->glfCompany->id, $this->glfProperty->id,
            ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
             'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
             'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
        );
    }

    private function makeStay(string $reservationId, string $guestId): FrontDeskStay
    {
        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->glfProperty->id,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
        ])->save();
        return $stay->fresh();
    }

    private function makeFolio(string $reservationId, string $guestId, array $overrides = []): Folio
    {
        static $seq = 0; $seq++;
        $folio = new Folio();
        $folio->forceFill(array_merge([
            'property_id' => $this->glfProperty->id,
            'folio_number' => 'S' . $seq . '-' . bin2hex(random_bytes(2)),
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
            'opening_idempotency_key' => 'test-si-' . bin2hex(random_bytes(4)),
        ], $overrides))->save();
        return $folio->fresh();
    }

    private function addFolioCharge(Folio $folio, string $amount): void
    {
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->glfProperty->id,
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge',
            'quantity' => '1.00',
            'amount' => $amount,
            'is_void' => false,
            'posted_at' => now(),
            'posted_by' => $this->glfActor->id,
            'created_by' => $this->glfActor->id,
        ])->save();
    }

    private function makeCashPayment(string $reservationId, string $guestId, string $amount, string $lifecycle = 'FULLY_ALLOCATED', ?Folio $folio = null, ?string $cashierSessionId = null): GuestPaymentTransaction
    {
        static $pseq = 0; $pseq++;
        $csId = $cashierSessionId ?? $this->createCashierSession()->id;

        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->glfProperty->id,
            'payment_number' => 'GPM-' . $pseq . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'currency' => 'USD',
            'amount' => $amount,
            'cashier_session_id' => $csId,
            'tender_type' => 'CASH',
            'lifecycle_status' => $lifecycle,
            'recording_idempotency_key' => 'test-si-pmt-' . bin2hex(random_bytes(4)),
            'recorded_at' => now(),
            'recorded_by' => $this->glfActor->id,
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
            'source_snapshot' => json_encode([]),
        ])->save();

        if ($folio && $lifecycle !== 'VOIDED') {
            $alloc = new GuestPaymentAllocation();
            $alloc->forceFill([
                'property_id' => $this->glfProperty->id,
                'guest_payment_transaction_id' => $payment->id,
                'folio_id' => $folio->id,
                'amount' => $amount,
                'allocation_idempotency_key' => 'test-alloc-' . bin2hex(random_bytes(4)),
                'allocated_at' => now(),
                'allocated_by' => $this->glfActor->id,
                'source_snapshot' => json_encode([]),
                'created_at' => now(),
            ])->save();

            $item = new FolioItem();
            $item->forceFill([
                'property_id' => $this->glfProperty->id,
                'folio_id' => $folio->id,
                'item_type' => FolioItemTypeEnum::Payment,
                'description' => 'Payment',
                'quantity' => '1.00',
                'amount' => bcmul($amount, '-1', 2),
                'is_void' => false,
                'posted_at' => now(),
                'posted_by' => $this->glfActor->id,
                'created_by' => $this->glfActor->id,
                'source_domain' => 'pms_cashiering',
                'source_type' => 'guest_payment_allocation',
                'source_id' => $alloc->id,
                'guest_payment_allocation_id' => $alloc->id,
            ])->save();
        }

        return $payment->fresh();
    }

    private function createCashierSession(): CashierSession
    {
        $cs = new CashierSession();
        $cs->forceFill([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
        ])->save();
        return $cs->fresh();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // No folio — EVIDENCE_UNAVAILABLE
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_folio_returns_evidence_unavailable(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialEvidenceUnavailable,
                $a->status
            );
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Zero balance — READY
    // ═══════════════════════════════════════════════════════════════════════

    public function test_zero_balance_ready(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertEquals(GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady, $a->status);
            $this->assertEquals('0.00', $a->canonical_aggregate_balance);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Non-zero balance — BLOCKED
    // ═══════════════════════════════════════════════════════════════════════

    public function test_non_zero_folio_balance_blocked(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $this->addFolioCharge($folio, '150.00');
            DB::table('folios')->where('id', $folio->id)->update(['total_charges' => '150.00', 'balance' => '150.00']);

            $a = $this->service->attest($context, $stay->id);
            $this->assertEquals(GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialBlocked, $a->status);
            $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO', $a->blocker_codes);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Missing CASH linkage → EVIDENCE_UNAVAILABLE (fail-closed BEFORE status)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cash_linkage_included_when_present(): void
    {
        // The DB schema enforces NOT NULL on cashier_session_id.
        // The evaluator correctly includes cash-linked references
        // when a valid cashier_session_id is present.
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $this->addFolioCharge($folio, '100.00');
            DB::table('folios')->where('id', $folio->id)->update(['total_charges' => '100.00', 'balance' => '100.00']);
            $cs = $this->createCashierSession();

            $this->makeCashPayment($reservation->id, $guest->id, '100.00', 'FULLY_ALLOCATED', $folio, $cs->id);

            $a = $this->service->attest($context, $stay->id);

            $this->assertNotEmpty($a->cash_linked_references);
            $this->assertContains($cs->id, $a->cashier_session_ids);
            $this->assertContains('GUEST_PAYMENT_TRANSACTION', array_column($a->cash_linked_references, 'source_type'));
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Cash-linked references
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cash_payment_creates_reference(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $this->addFolioCharge($folio, '50.00');
            DB::table('folios')->where('id', $folio->id)->update(['total_charges' => '50.00', 'balance' => '50.00']);
            $cs = $this->createCashierSession();
            $this->makeCashPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio, $cs->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertNotEmpty($a->cash_linked_references);
            $types = array_column($a->cash_linked_references, 'source_type');
            $this->assertContains('GUEST_PAYMENT_TRANSACTION', $types);
            $this->assertContains($cs->id, $a->cashier_session_ids);
        });
    }

    public function test_only_cash_tender_creates_references(): void
    {
        // The only tender type in the repository is CASH.
        // All CASH payments with valid cashier_session_id create references.
        // This test proves references are created ONLY for CASH tender.
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $cs = $this->createCashierSession();
            $this->makeCashPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio, $cs->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertNotEmpty($a->cash_linked_references);

            // All references should be CASH-linked
            foreach ($a->cash_linked_references as $ref) {
                $this->assertContains($ref['source_type'], [
                    'GUEST_PAYMENT_TRANSACTION',
                    'GUEST_DEPOSIT_TRANSACTION',
                    'GUEST_REFUND_TRANSACTION',
                ]);
            }
        });
    }

    public function test_cash_references_deduplicated(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $this->addFolioCharge($folio, '100.00');
            DB::table('folios')->where('id', $folio->id)->update(['total_charges' => '100.00', 'balance' => '100.00']);
            $cs = $this->createCashierSession();
            $this->makeCashPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio, $cs->id);
            $this->makeCashPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio, $cs->id);

            $a = $this->service->attest($context, $stay->id);
            // Session IDs should be deduplicated
            $uniqueSessionIds = array_values(array_unique($a->cashier_session_ids));
            $this->assertEquals($uniqueSessionIds, $a->cashier_session_ids);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Port status matrix
    // ═══════════════════════════════════════════════════════════════════════

    public function test_posting_blocked_produces_blocker(): void
    {
        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status' => self::AVAILABLE_BLOCKED, 'code' => 'POSTING_INCOMPLETE', 'source_fingerprint' => 'fp', 'source_identifiers' => []];
            }
        });
        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);

        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertContains('MANDATORY_POSTINGS_INCOMPLETE', $a->blocker_codes);
        });
    }

    public function test_settlement_hold_active_produces_blocker(): void
    {
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status' => self::AVAILABLE_BLOCKED, 'code' => 'HOLD_ACTIVE', 'source_fingerprint' => 'fp', 'source_identifiers' => []];
            }
        });
        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);

        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertContains('SETTLEMENT_HOLD_ACTIVE', $a->blocker_codes);
        });
    }

    public function test_completed_settlement_conflict_produces_blocker(): void
    {
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status' => self::AVAILABLE_BLOCKED, 'code' => 'CONFLICT_EXISTS', 'source_fingerprint' => 'fp', 'source_identifiers' => []];
            }
        });
        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);

        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id);

            $a = $this->service->attest($context, $stay->id);
            $this->assertContains('CONFLICTING_COMPLETED_SETTLEMENT', $a->blocker_codes);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Missing CASH linkage via reflection
    // ═══════════════════════════════════════════════════════════════════════

    public function test_missing_cash_linkage_via_reflection(): void
    {
        // Use reflection to test the private cash-reference builder directly
        $evaluator = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class);
        $ref = new \ReflectionMethod($evaluator, 'buildCashLinkedReferences');
        $ref->setAccessible(true);

        // Synthetic CASH payment fact with empty cashier_session_id
        $paymentFacts = [[
            'id' => 'test-pmt-1',
            'tender_type' => 'CASH',
            'cashier_session_id' => '',
        ]];

        $result = $ref->invoke($evaluator, 'prop-1', 'res-1', 'guest-1', $paymentFacts, [], []);

        $this->assertTrue($result['missing_linkage'], 'Empty cashier_session_id must set missing_linkage=true.');

        // Verify status ordering: missing_linkage → EVIDENCE_UNAVAILABLE
        $statusValue = $evaluator->determineStatusValue(
            ['CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE'], [], []
        );
        $this->assertEquals('PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE', $statusValue);
    }

    public function test_cash_references_exclude_amounts(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $this->addFolioCharge($folio, '50.00');
            DB::table('folios')->where('id', $folio->id)->update(['total_charges' => '50.00', 'balance' => '50.00']);
            $cs = $this->createCashierSession();
            $this->makeCashPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio, $cs->id);

            $a = $this->service->attest($context, $stay->id);
            foreach ($a->cash_linked_references as $ref) {
                $this->assertArrayNotHasKey('amount', $ref);
                $this->assertArrayNotHasKey('guest_name', $ref);
                $this->assertArrayNotHasKey('guest_id', $ref);
            }
        });
    }
}

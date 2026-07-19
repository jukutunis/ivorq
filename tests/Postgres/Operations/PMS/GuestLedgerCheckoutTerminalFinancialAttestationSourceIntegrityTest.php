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
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
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

    // Test ports — constructed directly to guarantee binding
    private GuestLedgerPostingCompletenessParticipationPort $pcPort;
    private GuestLedgerSettlementHoldParticipationPort $shPort;
    private GuestLedgerCompletedSettlementConflictParticipationPort $csPort;

    private CashierSession $cashierSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);

        // Create one cashier session shared across tests
        $this->cashierSession = new CashierSession();
        $this->cashierSession->forceFill([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
        ])->save();

        // Default: all ports AVAILABLE_CLEAR
        $this->buildServiceWithPorts('AVAILABLE_CLEAR', 'AVAILABLE_CLEAR', 'AVAILABLE_CLEAR');
    }

    private function buildServiceWithPorts(
        string $pcStatus, string $shStatus, string $csStatus,
        ?string $pcCode = null, ?string $shCode = null, ?string $csCode = null,
    ): void {
        $pcCode = $pcCode ?? $pcStatus;
        $shCode = $shCode ?? $shStatus;
        $csCode = $csCode ?? $csStatus;

        $this->pcPort = new class($pcStatus, $pcCode) implements GuestLedgerPostingCompletenessParticipationPort {
            public function __construct(private string $s, private string $c) {}
            public function participate(string $r, string $p): array {
                return ['status' => $this->s, 'code' => $this->c, 'source_fingerprint' => 'fp_pc', 'source_identifiers' => []];
            }
        };
        $this->shPort = new class($shStatus, $shCode) implements GuestLedgerSettlementHoldParticipationPort {
            public function __construct(private string $s, private string $c) {}
            public function participate(string $r, string $p): array {
                return ['status' => $this->s, 'code' => $this->c, 'source_fingerprint' => 'fp_sh', 'source_identifiers' => []];
            }
        };
        $this->csPort = new class($csStatus, $csCode) implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function __construct(private string $s, private string $c) {}
            public function participate(string $r, string $p): array {
                return ['status' => $this->s, 'code' => $this->c, 'source_fingerprint' => 'fp_cs', 'source_identifiers' => []];
            }
        };

        $this->service = new GuestLedgerCheckoutTerminalFinancialAttestationService(
            app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class),
            $this->lockService,
            $this->pcPort, $this->shPort, $this->csPort,
        );
    }

    // ── Fixture helpers (readable arrange sections) ──────────────────────
    private function openBusinessDate(): PropertyBusinessDate
    {
        $bd = new PropertyBusinessDate();
        $bd->forceFill([
            'property_id' => $this->glfProperty->id, 'business_date' => today(),
            'status' => PropertyBusinessDateStatusEnum::Open, 'is_open' => true,
            'timezone_snapshot' => 'UTC', 'opened_by' => $this->glfActor->id, 'opened_at' => now(),
        ])->save();
        return $bd->fresh();
    }

    private function acquireContext(): PropertyBusinessDateOperationalLockContext
    {
        $bd = $this->openBusinessDate();
        return $this->lockService->acquire($this->glfCompany->id, $this->glfProperty->id, [
            'property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
            'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
            'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString(),
        ]);
    }

    private function createStay(string $reservationId, string $guestId): FrontDeskStay
    {
        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->glfProperty->id, 'reservation_id' => $reservationId,
            'guest_id' => $guestId, 'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->glfActor->id, 'updated_by' => $this->glfActor->id,
        ])->save();
        return $stay->fresh();
    }

    private function createFolio(string $reservationId, string $guestId, array $overrides = []): Folio
    {
        static $seq = 0; $seq++;
        $folio = new Folio();
        $folio->forceFill(array_merge([
            'property_id' => $this->glfProperty->id, 'folio_number' => 'SI-' . $seq . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $reservationId, 'guest_id' => $guestId,
            'status' => 'open', 'currency' => 'USD', 'window_number' => $seq,
            'total_charges' => '0.00', 'total_payments' => '0.00',
            'total_deposits' => '0.00', 'total_ar_transfers' => '0.00', 'balance' => '0.00',
            'opening_idempotency_key' => 'si-' . bin2hex(random_bytes(4)),
        ], $overrides))->save();
        return $folio->fresh();
    }

    private function addCharge(Folio $folio, string $amount): FolioItem
    {
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->glfProperty->id, 'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge, 'description' => 'Room charge',
            'quantity' => '1.00', 'amount' => $amount, 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->glfActor->id, 'created_by' => $this->glfActor->id,
        ])->save();
        return $item->fresh();
    }

    private function updateCachedTotals(Folio $folio, array $totals): void
    {
        DB::table('folios')->where('id', $folio->id)->update($totals);
    }

    private function createPayment(
        string $reservationId, string $guestId, string $amount,
        string $lifecycle = 'FULLY_ALLOCATED', ?Folio $folio = null,
    ): GuestPaymentTransaction {
        static $pn = 0; $pn++;
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->glfProperty->id, 'payment_number' => 'GPM-' . $pn . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $reservationId, 'guest_id' => $guestId, 'currency' => 'USD',
            'amount' => $amount, 'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => $lifecycle,
            'recording_idempotency_key' => 'si-p-' . bin2hex(random_bytes(4)),
            'recorded_at' => now(), 'recorded_by' => $this->glfActor->id,
            'created_by' => $this->glfActor->id, 'updated_by' => $this->glfActor->id,
            'source_snapshot' => json_encode([]),
        ])->save();

        if ($folio && $lifecycle !== 'VOIDED') {
            $this->createAllocationAndFolioItem($payment, $folio, $amount);
        }

        return $payment->fresh();
    }

    private function createAllocationAndFolioItem(GuestPaymentTransaction $payment, Folio $folio, string $amount): GuestPaymentAllocation
    {
        $alloc = new GuestPaymentAllocation();
        $alloc->forceFill([
            'property_id' => $this->glfProperty->id, 'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $folio->id, 'amount' => $amount,
            'allocation_idempotency_key' => 'si-a-' . bin2hex(random_bytes(4)),
            'allocated_at' => now(), 'allocated_by' => $this->glfActor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();

        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->glfProperty->id, 'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Payment, 'description' => 'Payment allocation',
            'quantity' => '1.00', 'amount' => bcmul($amount, '-1', 2), 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->glfActor->id, 'created_by' => $this->glfActor->id,
            'source_domain' => 'pms_cashiering', 'source_type' => 'guest_payment_allocation',
            'source_id' => $alloc->id, 'guest_payment_allocation_id' => $alloc->id,
        ])->save();
        return $alloc->fresh();
    }

    private function createDeposit(string $reservationId, string $guestId, string $amount, string $lifecycle = 'RECORDED'): GuestDepositTransaction
    {
        static $dn = 0; $dn++;
        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->glfProperty->id, 'deposit_number' => 'DEP-' . $dn . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $reservationId, 'guest_id' => $guestId, 'currency' => 'USD',
            'amount' => $amount, 'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => $lifecycle,
            'recording_idempotency_key' => 'si-d-' . bin2hex(random_bytes(4)),
            'recorded_at' => now(), 'recorded_by' => $this->glfActor->id,
            'created_by' => $this->glfActor->id, 'updated_by' => $this->glfActor->id,
            'source_snapshot' => json_encode([]),
        ])->save();
        return $deposit->fresh();
    }

    private function applyDeposit(GuestDepositTransaction $deposit, Folio $folio, string $amount): GuestDepositApplication
    {
        $app = new GuestDepositApplication();
        $app->forceFill([
            'property_id' => $this->glfProperty->id, 'guest_deposit_transaction_id' => $deposit->id,
            'folio_id' => $folio->id, 'amount' => $amount,
            'application_idempotency_key' => 'si-da-' . bin2hex(random_bytes(4)),
            'applied_at' => now(), 'applied_by' => $this->glfActor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();

        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->glfProperty->id, 'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Deposit, 'description' => 'Deposit application',
            'quantity' => '1.00', 'amount' => bcmul($amount, '-1', 2), 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->glfActor->id, 'created_by' => $this->glfActor->id,
            'guest_deposit_application_id' => $app->id,
        ])->save();
        return $app->fresh();
    }

    private function attest(string $stayId): GuestLedgerCheckoutTerminalFinancialAttestation
    {
        return $this->service->attest($this->acquireContext(), $stayId);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FOLIO TESTS
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_folio_returns_evidence_unavailable(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            // Zero folios created

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialEvidenceUnavailable,
                $result->status
            );
            $this->assertContains('CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE', $result->evidence_unavailable_codes);
        });
    }

    public function test_zero_balance_ready(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $this->createFolio($reservation->id, $guest->id);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady,
                $result->status
            );
            $this->assertEquals('0.00', $result->canonical_aggregate_balance);
            $this->assertEmpty($result->blocker_codes);
            $this->assertEmpty($result->review_reasons);
        });
    }

    public function test_non_zero_balance_blocked(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            $this->addCharge($folio, '150.00');
            $this->updateCachedTotals($folio, ['total_charges' => '150.00', 'balance' => '150.00']);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialBlocked,
                $result->status
            );
            $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO', $result->blocker_codes);
        });
    }

    public function test_closed_folio_review_required(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $this->createFolio($reservation->id, $guest->id, ['status' => 'closed']);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED', $result->review_reasons);
        });
    }

    public function test_void_folio_review_required(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $this->createFolio($reservation->id, $guest->id, ['status' => 'void']);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED', $result->review_reasons);
        });
    }

    public function test_cached_totals_mismatch_review_required(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            // Add charge but do NOT update cached totals — mismatch
            $this->addCharge($folio, '50.00');

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertContains('FOLIO_CACHED_TOTALS_MISMATCH', $result->review_reasons);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PAYMENT TESTS
    // ═══════════════════════════════════════════════════════════════════════

    public function test_unresolved_payment_blocked(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            $this->addCharge($folio, '100.00');
            $this->updateCachedTotals($folio, ['total_charges' => '100.00', 'balance' => '100.00']);
            // Payment recorded but NOT allocated
            $this->createPayment($reservation->id, $guest->id, '100.00', 'RECORDED');

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PORT STATUS TESTS (3 ports × 3 non-clear statuses = 9 tests)
    // ═══════════════════════════════════════════════════════════════════════

    private function doPortTest(string $portKey, string $status, string $expectedCode, string $field): void
    {
        $pcStatus = $portKey === 'pc' ? $status : 'AVAILABLE_CLEAR';
        $shStatus = $portKey === 'sh' ? $status : 'AVAILABLE_CLEAR';
        $csStatus = $portKey === 'cs' ? $status : 'AVAILABLE_CLEAR';
        $this->buildServiceWithPorts($pcStatus, $shStatus, $csStatus);

        DB::transaction(function () use ($expectedCode, $field) {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $this->createFolio($reservation->id, $guest->id);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertContains($expectedCode, $result->$field,
                "Port {$expectedCode} should appear in {$field}.");
        });

        // Restore clear ports
        $this->buildServiceWithPorts('AVAILABLE_CLEAR', 'AVAILABLE_CLEAR', 'AVAILABLE_CLEAR');
    }

    public function test_posting_blocked():     void { $this->doPortTest('pc','AVAILABLE_BLOCKED','MANDATORY_POSTINGS_INCOMPLETE','blocker_codes'); }
    public function test_posting_review():      void { $this->doPortTest('pc','REVIEW_REQUIRED','POSTING_COMPLETENESS_REVIEW_REQUIRED','review_reasons'); }
    public function test_posting_unavailable(): void { $this->doPortTest('pc','EVIDENCE_UNAVAILABLE','EVIDENCE_UNAVAILABLE','evidence_unavailable_codes'); }
    public function test_hold_blocked():        void { $this->doPortTest('sh','AVAILABLE_BLOCKED','SETTLEMENT_HOLD_ACTIVE','blocker_codes'); }
    public function test_hold_review():         void { $this->doPortTest('sh','REVIEW_REQUIRED','SETTLEMENT_HOLD_REVIEW_REQUIRED','review_reasons'); }
    public function test_hold_unavailable():    void { $this->doPortTest('sh','EVIDENCE_UNAVAILABLE','EVIDENCE_UNAVAILABLE','evidence_unavailable_codes'); }
    public function test_conflict_blocked():    void { $this->doPortTest('cs','AVAILABLE_BLOCKED','CONFLICTING_COMPLETED_SETTLEMENT','blocker_codes'); }
    public function test_conflict_review():     void { $this->doPortTest('cs','REVIEW_REQUIRED','COMPLETED_SETTLEMENT_CONFLICT_REVIEW_REQUIRED','review_reasons'); }
    public function test_conflict_unavailable():void { $this->doPortTest('cs','EVIDENCE_UNAVAILABLE','EVIDENCE_UNAVAILABLE','evidence_unavailable_codes'); }

    public function test_posting_malformed_result_fails_closed(): void
    {
        $this->pcPort = new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $r, string $p): array {
                return ['status' => 'INVALID_STATUS', 'code' => 'bad', 'source_fingerprint' => null, 'source_identifiers' => []];
            }
        };
        $this->service = new GuestLedgerCheckoutTerminalFinancialAttestationService(
            app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class),
            $this->lockService, $this->pcPort, $this->shPort, $this->csPort,
        );

        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $this->createFolio($reservation->id, $guest->id);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialEvidenceUnavailable,
                $result->status,
                'Malformed port result must fail closed.'
            );
        });

        $this->buildServiceWithPorts('AVAILABLE_CLEAR', 'AVAILABLE_CLEAR', 'AVAILABLE_CLEAR');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CASH-LINKED REFERENCE TESTS
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cash_payment_creates_reference(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            $this->addCharge($folio, '50.00');
            $this->updateCachedTotals($folio, ['total_charges' => '50.00', 'balance' => '50.00']);
            $this->createPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $this->assertNotEmpty($result->cash_linked_references);
            $types = array_column($result->cash_linked_references, 'source_type');
            $this->assertContains('GUEST_PAYMENT_TRANSACTION', $types);
            $this->assertContains($this->cashierSession->id, $result->cashier_session_ids);
        });
    }

    public function test_cash_deposit_creates_reference(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            $deposit = $this->createDeposit($reservation->id, $guest->id, '100.00');
            $this->applyDeposit($deposit, $folio, '100.00');
            DB::table('guest_deposit_transactions')->where('id', $deposit->id)
                ->update(['lifecycle_status' => 'RESOLVED']);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            $types = array_column($result->cash_linked_references, 'source_type');
            $this->assertContains('GUEST_DEPOSIT_TRANSACTION', $types);
        });
    }

    public function test_cash_references_exclude_amounts(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            $this->addCharge($folio, '50.00');
            $this->updateCachedTotals($folio, ['total_charges' => '50.00', 'balance' => '50.00']);
            $this->createPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio);

            $result = $this->service->attest($this->acquireContext(), $stay->id);

            foreach ($result->cash_linked_references as $ref) {
                $this->assertArrayNotHasKey('amount', $ref, 'Cash reference must not include amount.');
                $this->assertArrayNotHasKey('guest_id', $ref, 'Cash reference must not include guest PII.');
                $this->assertArrayNotHasKey('guest_name', $ref, 'Cash reference must not include guest name.');
            }
        });
    }

    public function test_no_cashier_sessions_query(): void
    {
        DB::transaction(function () {
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->createStay($reservation->id, $guest->id);
            $folio = $this->createFolio($reservation->id, $guest->id);
            $this->addCharge($folio, '50.00');
            $this->updateCachedTotals($folio, ['total_charges' => '50.00', 'balance' => '50.00']);
            $this->createPayment($reservation->id, $guest->id, '50.00', 'FULLY_ALLOCATED', $folio);

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->service->attest($this->acquireContext(), $stay->id);

            foreach (DB::getQueryLog() as $entry) {
                $sql = $entry['query'] ?? '';
                $this->assertStringNotContainsString('cashier_sessions', $sql,
                    'GLF-E must not query cashier_sessions.');
            }
            DB::disableQueryLog();
        });
    }

    public function test_missing_cash_linkage_defensive_coverage(): void
    {
        // Reflection-based defensive test for impossible/legacy-corrupt source evidence
        $evaluator = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class);
        $ref = new \ReflectionMethod($evaluator, 'buildCashLinkedReferences');
        $ref->setAccessible(true);

        $result = $ref->invoke($evaluator, 'p', 'r', 'g',
            [['id' => 'x', 'tender_type' => 'CASH', 'cashier_session_id' => '']],
            [], []
        );

        $this->assertTrue($result['missing_linkage'], 'Empty cashier_session_id must produce missing_linkage=true.');

        // Prove status ordering: missing linkage → EVIDENCE_UNAVAILABLE
        $this->assertEquals(
            'PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE',
            $evaluator->determineStatusValue(['CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE'], [], [])
        );
    }
}

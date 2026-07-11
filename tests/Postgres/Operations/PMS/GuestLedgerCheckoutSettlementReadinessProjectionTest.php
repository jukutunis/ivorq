<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestLedgerSettlementReadinessStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Operations\PMS\Services\Adapters\UnavailableCompletedSettlementConflictAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailablePostingCompletenessAdapter;
use Modules\Operations\PMS\Services\Adapters\UnavailableSettlementHoldAdapter;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GuestLedgerCheckoutSettlementReadinessProjectionTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutSettlementReadinessProjectionService $service;
    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private User $otherActor;
    private CashierSession $cashierSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        $this->company = $this->glfCompany;
        $this->property = $this->glfProperty;
        $this->otherProperty = $this->glfOtherProperty;
        $this->actor = $this->glfActor;
        $this->otherActor = $this->glfOtherActor;

        // Create a cashier session for payment/deposit fixtures
        $this->cashierSession = new CashierSession();
        $this->cashierSession->forceFill([
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->actor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->actor->id,
        ])->save();

        $perm = Permission::firstOrCreate(['name' => GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo($perm);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        // Bind production unavailable adapters by default
        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, UnavailablePostingCompletenessAdapter::class);
        app()->singleton(GuestLedgerSettlementHoldReadPort::class, UnavailableSettlementHoldAdapter::class);
        app()->singleton(GuestLedgerCompletedSettlementConflictReadPort::class, UnavailableCompletedSettlementConflictAdapter::class);

        $this->service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeStay(?Reservation $reservation = null, ?Guest $guest = null): FrontDeskStay
    {
        $reservation = $reservation ?? $this->makeGlfReservation();
        $guest = $guest ?? $reservation->primaryGuest;

        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $stay->fresh();
    }

    private function makeFolioForStay(Reservation $reservation, Guest $guest, string $status = 'open'): Folio
    {
        return $this->makeGlfFolio($reservation, $guest, ['status' => $status]);
    }

    private function makePayment(Reservation $reservation, Guest $guest, string $amount, string $status = 'RECORDED'): GuestPaymentTransaction
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-TEST-' . uniqid(),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => $amount,
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => $status,
            'recording_idempotency_key' => 'test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();
        return $payment->fresh();
    }

    private function allocatePayment(GuestPaymentTransaction $payment, Folio $folio, string $amount): GuestPaymentAllocation
    {
        $allocation = new GuestPaymentAllocation();
        $allocation->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $folio->id,
            'amount' => $amount,
            'allocation_idempotency_key' => 'test-alloc-' . uniqid(),
            'allocated_at' => now(),
            'allocated_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        // Create matching FolioItem
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id,
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Payment,
            'description' => 'Payment allocation',
            'quantity' => '1.00',
            'amount' => bcmul($amount, '-1', 2),
            'is_void' => false,
            'posted_at' => now(),
            'posted_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'source_domain' => 'pms_cashiering',
            'source_type' => 'guest_payment_allocation',
            'source_id' => $allocation->id,
            'guest_payment_allocation_id' => $allocation->id,
        ])->save();

        return $allocation->fresh();
    }

    private function authenticate(User $actor): void
    {
        auth()->login($actor);
        $this->actingAs($actor);
    }

    // ── 1. Output Contract ───────────────────────────────────────────────────

    public function test_projection_has_required_fields(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $guest = $reservation->primaryGuest;
        $this->makeFolioForStay($reservation, $guest);

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertNotNull($result->projection_version);
        $this->assertNotNull($result->status);
        $this->assertNotNull($result->evaluated_at);
        $this->assertNotNull($result->source_fingerprint);
        $this->assertNotEmpty($result->property_id);
        $this->assertEquals($stay->id, $result->front_desk_stay_id);
        $this->assertNotNull($result->markers);
        $this->assertIsArray($result->blocker_codes);
        $this->assertIsArray($result->review_reasons);
        $this->assertIsArray($result->evidence_unavailable_codes);
    }

    // ── 2. Exact status values ───────────────────────────────────────────────

    public function test_status_is_valid_enum_value(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertContains($result->status, GuestLedgerSettlementReadinessStatusEnum::cases());
    }

    // ── 3. Deterministic code ordering ───────────────────────────────────────

    public function test_codes_are_sorted_deterministically(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $r1 = $this->service->project($this->actor, $stay->id);
        $r2 = $this->service->project($this->actor, $stay->id);

        $this->assertEquals($r1->blocker_codes, $r2->blocker_codes);
        $this->assertEquals($r1->review_reasons, $r2->review_reasons);
        $this->assertEquals($r1->evidence_unavailable_codes, $r2->evidence_unavailable_codes);
    }

    // ── 4. Deterministic fingerprint ─────────────────────────────────────────

    public function test_fingerprint_stable_on_unchanged_facts(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $r1 = $this->service->project($this->actor, $stay->id);

        // Wait a moment to prove evaluated_at is NOT in the fingerprint
        usleep(100000);

        $r2 = $this->service->project($this->actor, $stay->id);

        $this->assertEquals($r1->source_fingerprint, $r2->source_fingerprint);
    }

    // ── 5. evaluated_at is server-owned ──────────────────────────────────────

    public function test_evaluated_at_is_server_owned(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertNotEmpty($result->evaluated_at);
        // Timestamp changes on repeated evaluation
        usleep(100000);
        $r2 = $this->service->project($this->actor, $stay->id);
        $this->assertNotEquals($result->evaluated_at, $r2->evaluated_at);
    }

    // ── 6. No Folio → EVIDENCE_UNAVAILABLE ───────────────────────────────────

    public function test_no_folio_returns_evidence_unavailable(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        // No folio created

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
            $result->status
        );
        $this->assertContains('CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE', $result->evidence_unavailable_codes);
    }

    // ── 7. One zero-balance Folio does NOT imply READY ───────────────────────

    public function test_zero_balance_folio_does_not_imply_ready(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $reservation->primaryGuest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertNotEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady,
            $result->status
        );
    }

    // ── 8. Production unavailable ports prevent READY ────────────────────────

    public function test_production_unavailable_ports_prevent_ready(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $reservation->primaryGuest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
            $result->status
        );
        $this->assertContains('POSTING_COMPLETENESS_EVIDENCE_UNAVAILABLE', $result->evidence_unavailable_codes);
    }

    // ── 9. All test ports clear + all financial resolved → READY ─────────────

    public function test_all_ports_clear_and_financial_resolved_produces_ready(): void
    {
        // Bind test ports that return CLEAR
        app()->forgetInstance(GuestLedgerPostingCompletenessReadPort::class);
        app()->forgetInstance(GuestLedgerSettlementHoldReadPort::class);
        app()->forgetInstance(GuestLedgerCompletedSettlementConflictReadPort::class);

        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerSettlementHoldReadPort::class, function () {
            return new class implements GuestLedgerSettlementHoldReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictReadPort::class, function () {
            return new class implements GuestLedgerCompletedSettlementConflictReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });

        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);

        // Zero balance folio with matched cached totals
        $folio->forceFill([
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
        ])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementReady,
            $result->status
        );
    }

    // ── 10. Unavailable precedence ───────────────────────────────────────────

    public function test_unavailable_precedence_over_review(): void
    {
        // Test port returns REVIEW_REQUIRED but unavailable ports exist
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        // Add a review condition on the folio: currency mismatch via multiple currencies
        $folio2 = $this->makeGlfFolio($reservation, $reservation->primaryGuest, [
            'currency' => 'EUR',
            'window_number' => 2,
        ]);

        $result = $this->service->project($this->actor, $stay->id);

        // Unavailable ports prevent READY, so EVIDENCE_UNAVAILABLE wins
        $this->assertEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable,
            $result->status
        );
    }

    // ── Multi-Folio tests ────────────────────────────────────────────────────

    public function test_all_reservation_folios_included(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $guest = $reservation->primaryGuest;
        $f1 = $this->makeGlfFolio($reservation, $guest, ['window_number' => 1]);
        $f2 = $this->makeGlfFolio($reservation, $guest, ['window_number' => 2]);

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertEquals(2, $result->folio_count);
        $this->assertContains($f1->id, $result->folio_ids);
        $this->assertContains($f2->id, $result->folio_ids);
    }

    public function test_each_folio_independently_zero_for_ready(): void
    {
        // Bind test ports as CLEAR
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);

        // Folio 1: zero balance
        $f1 = $this->makeFolioForStay($reservation, $guest);
        $f1->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        // Folio 2: has outstanding balance
        $f2 = $this->makeGlfFolio($reservation, $guest, ['window_number' => 2]);
        // Add a room charge to folio 2
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id,
            'folio_id' => $f2->id,
            'item_type' => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room charge',
            'quantity' => '1.00',
            'amount' => '100.00',
            'is_void' => false,
            'posted_at' => now(),
            'posted_by' => $this->actor->id,
            'created_by' => $this->actor->id,
        ])->save();
        $f2->forceFill(['total_charges' => '100.00', 'balance' => '100.00'])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked,
            $result->status
        );
        $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO', $result->blocker_codes);
    }

    public function test_offsetting_folios_do_not_qualify(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);

        // Folio 1: +100 (debit)
        $f1 = $this->makeFolioForStay($reservation, $guest);
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id,
            'folio_id' => $f1->id,
            'item_type' => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge', 'quantity' => '1.00', 'amount' => '100.00',
            'is_void' => false, 'posted_at' => now(), 'posted_by' => $this->actor->id,
            'created_by' => $this->actor->id,
        ])->save();
        $f1->forceFill(['total_charges' => '100.00', 'balance' => '100.00'])->save();

        // Folio 2: -100 (credit — payment via proper allocation)
        $f2 = $this->makeGlfFolio($reservation, $guest, ['window_number' => 2]);
        $payment = $this->makePayment($reservation, $guest, '100.00', 'FULLY_ALLOCATED');
        $this->allocatePayment($payment, $f2, '100.00');
        $f2->forceFill(['total_payments' => '100.00', 'balance' => '-100.00'])->save();

        $result = $service->project($this->actor, $stay->id);

        // Each folio individually non-zero → not ready
        $this->assertEquals(
            GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementBlocked,
            $result->status
        );
        $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO', $result->blocker_codes);
    }

    // ── Currency conflict ────────────────────────────────────────────────────

    public function test_currency_conflict_requires_review(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $f1 = $this->makeFolioForStay($reservation, $guest);
        $f2 = $this->makeGlfFolio($reservation, $guest, [
            'currency' => 'EUR',
            'window_number' => 2,
        ]);

        $result = $this->service->project($this->actor, $stay->id);

        $this->assertTrue(
            in_array('FOLIO_CURRENCY_CONFLICT', $result->review_reasons) ||
            $result->status === GuestLedgerSettlementReadinessStatusEnum::GuestLedgerSettlementEvidenceUnavailable
        );
    }

    // ── Closed/void Folio requires review ────────────────────────────────────

    public function test_closed_folio_requires_review(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeGlfFolio($reservation, $guest, ['status' => FolioStatusEnum::Closed->value]);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED', $result->review_reasons);
    }

    // ── Cached totals mismatch ───────────────────────────────────────────────

    public function test_cached_totals_mismatch_requires_review(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);

        // Add a room charge folio item but DO NOT update cached totals
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id,
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room night', 'quantity' => '1.00', 'amount' => '150.00',
            'is_void' => false, 'posted_at' => now(), 'posted_by' => $this->actor->id,
            'created_by' => $this->actor->id,
        ])->save();

        // Cached totals still say 0 but fresh calculation says 150
        $folio->forceFill(['total_charges' => '0.00', 'balance' => '0.00'])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('FOLIO_CACHED_TOTALS_MISMATCH', $result->review_reasons);
    }

    // ── Payment evaluation ───────────────────────────────────────────────────

    public function test_unresolved_payment_blocks(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $payment = $this->makePayment($reservation, $guest, '100.00');
        // Payment recorded but not allocated

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
    }

    public function test_fully_allocated_payment_resolves(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '100.00', 'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00'])->save();

        $payment = $this->makePayment($reservation, $guest, '100.00', 'FULLY_ALLOCATED');
        $this->allocatePayment($payment, $folio, '100.00');

        $result = $service->project($this->actor, $stay->id);

        $this->assertNotContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
    }

    public function test_partial_allocation_blocks(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $payment = $this->makePayment($reservation, $guest, '100.00', 'PARTIALLY_ALLOCATED');
        $this->allocatePayment($payment, $folio, '40.00');

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_actor_auth_mismatch_throws(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        // Try to project with a different actor
        $otherUser = User::create([
            'name' => 'Other', 'email' => 'other-test-' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'is_active' => true,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->service->project($otherUser, $stay->id);
    }

    public function test_missing_permission_throws(): void
    {
        // Create user without the view permission
        $noPermUser = User::create([
            'name' => 'NoPerm', 'email' => 'noperm-' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'is_active' => true,
        ]);
        $noPermUser->properties()->attach($this->property->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
        $this->authenticate($noPermUser);

        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $this->expectException(AuthorizationException::class);
        $this->service->project($noPermUser, $stay->id);
    }

    public function test_unknown_stay_non_disclosing(): void
    {
        $this->authenticate($this->actor);
        $this->expectException(NotFoundException::class);
        $this->service->project($this->actor, '01JUNK1234567890ABCDEFGHIJ');
    }

    public function test_cross_property_stay_non_disclosing(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation($this->otherProperty);
        $guest = Guest::withoutGlobalScope('property')->find($reservation->primary_guest_id);
        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $this->expectException(NotFoundException::class);
        $this->service->project($this->actor, $stay->id);
    }

    // ── Read-only guarantee ──────────────────────────────────────────────────

    public function test_projection_does_not_mutate_stay(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $beforeStatus = $stay->fresh()->status;

        $this->service->project($this->actor, $stay->id);

        $this->assertEquals($beforeStatus, $stay->fresh()->status);
    }

    public function test_projection_does_not_mutate_folio(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $beforeStatus = $folio->fresh()->status;
        $beforeBalance = (string) $folio->fresh()->balance;

        $this->service->project($this->actor, $stay->id);

        $folio->refresh();
        $this->assertEquals($beforeStatus, $folio->status);
        $this->assertEquals($beforeBalance, (string) $folio->balance);
    }

    // ── Deposit evaluation ───────────────────────────────────────────────────

    public function test_unresolved_deposit_blocks(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id,
            'deposit_number' => 'GDP-TEST-' . uniqid(),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '200.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'dep-test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('GUEST_DEPOSIT_UNRESOLVED', $result->blocker_codes);
    }

    // ── AR transfer evaluation ───────────────────────────────────────────────

    public function test_ar_pending_blocks(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $ar = new GuestArTransferRequest();
        $ar->forceFill([
            'property_id' => $this->property->id,
            'transfer_number' => 'GAR-TEST-' . uniqid(),
            'folio_id' => $folio->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '50.00',
            'lifecycle_status' => GuestArTransferStatusEnum::Requested->value,
            'request_reason_code' => 'TEST',
            'request_idempotency_key' => 'ar-test-' . uniqid(),
            'requested_at' => now(),
            'requested_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('GUEST_AR_TRANSFER_PENDING', $result->blocker_codes);
    }

    // ── External gates ───────────────────────────────────────────────────────

    public function test_incomplete_postings_block(): void
    {
        app()->forgetInstance(GuestLedgerPostingCompletenessReadPort::class);
        app()->forgetInstance(GuestLedgerSettlementHoldReadPort::class);
        app()->forgetInstance(GuestLedgerCompletedSettlementConflictReadPort::class);

        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_BLOCKED, 'code' => 'MANDATORY_POSTINGS_INCOMPLETE', 'message' => 'Unposted room charges exist.'];
                }
            };
        });
        app()->singleton(GuestLedgerSettlementHoldReadPort::class, function () {
            return new class implements GuestLedgerSettlementHoldReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictReadPort::class, function () {
            return new class implements GuestLedgerCompletedSettlementConflictReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });

        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);
        $folio->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertContains('MANDATORY_POSTINGS_INCOMPLETE', $result->blocker_codes);
    }

    // ── BCMath precision ─────────────────────────────────────────────────────

    public function test_exact_bcmath_aggregate_balance(): void
    {
        $this->bindClearPorts();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation);
        $folio = $this->makeFolioForStay($reservation, $guest);

        // Add precise amounts
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id,
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge,
            'description' => 'Test', 'quantity' => '1.00', 'amount' => '99.99',
            'is_void' => false, 'posted_at' => now(), 'posted_by' => $this->actor->id,
            'created_by' => $this->actor->id,
        ])->save();
        $folio->forceFill(['total_charges' => '99.99', 'balance' => '99.99'])->save();

        $result = $service->project($this->actor, $stay->id);

        $this->assertEquals('99.99', $result->canonical_aggregate_balance);
    }

    // ── Repeated projection stable ───────────────────────────────────────────

    public function test_repeated_projection_stable(): void
    {
        $this->authenticate($this->actor);
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);
        $this->makeFolioForStay($reservation, $reservation->primaryGuest);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->service->project($this->actor, $stay->id);
        }

        $first = $results[0];
        foreach ($results as $r) {
            $this->assertEquals($first->status, $r->status);
            $this->assertEquals($first->source_fingerprint, $r->source_fingerprint);
            $this->assertEquals($first->canonical_aggregate_balance, $r->canonical_aggregate_balance);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function bindClearPorts(): void
    {
        app()->forgetInstance(GuestLedgerPostingCompletenessReadPort::class);
        app()->forgetInstance(GuestLedgerSettlementHoldReadPort::class);
        app()->forgetInstance(GuestLedgerCompletedSettlementConflictReadPort::class);

        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerSettlementHoldReadPort::class, function () {
            return new class implements GuestLedgerSettlementHoldReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictReadPort::class, function () {
            return new class implements GuestLedgerCompletedSettlementConflictReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
    }
}

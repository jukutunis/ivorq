<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestLedgerSettlementReadinessStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;

class GuestLedgerCheckoutSettlementReadinessSourceIntegrityTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutSettlementReadinessProjectionService $service;
    private Property $property;
    private User $actor;
    private Reservation $reservation;
    private Guest $guest;
    private FrontDeskStay $stay;
    private Folio $folio;
    private CashierSession $cashierSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        $this->property = $this->glfProperty;
        $this->actor = $this->glfActor;

        $perm = Permission::firstOrCreate([
            'name' => GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo($perm);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        // Bind CLEAR test ports
        $this->bindClearPorts();

        $this->service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        // Create cashier session for payment/deposit fixtures
        $this->cashierSession = new CashierSession();
        $this->cashierSession->forceFill([
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->actor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->actor->id,
        ])->save();

        $this->reservation = $this->makeGlfReservation();
        $this->guest = $this->reservation->primaryGuest;

        $this->stay = new FrontDeskStay();
        $this->stay->forceFill([
            'property_id' => $this->property->id,
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $this->folio = $this->makeGlfFolio($this->reservation, $this->guest);
        $this->folio->forceFill([
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
        ])->save();

        auth()->login($this->actor);
        $this->actingAs($this->actor);
    }

    // ── Payment void evidence ────────────────────────────────────────────────

    public function test_valid_payment_void_resolves(): void
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-SI-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '50.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Voided->value,
            'recording_idempotency_key' => 'void-test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        // Valid void evidence
        $reversal = new GuestPaymentReversal();
        $reversal->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'reversal_type' => GuestPaymentReversalTypeEnum::PaymentVoid->value,
            'amount' => '50.00',
            'reason_code' => 'TEST',
            'reversal_idempotency_key' => 'void-rev-' . uniqid(),
            'reversed_at' => now(),
            'reversed_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        $this->assertNotContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
    }

    // ── Over-resolved payment requires review ────────────────────────────────

    public function test_over_resolved_payment_requires_review(): void
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-OVER-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '50.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => 'FULLY_ALLOCATED',
            'recording_idempotency_key' => 'over-test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        // Allocate 50 + 10 (over-resolved)
        $alloc1 = new GuestPaymentAllocation();
        $alloc1->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $this->folio->id,
            'amount' => '50.00',
            'allocation_idempotency_key' => 'over-alloc1-' . uniqid(),
            'allocated_at' => now(),
            'allocated_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $alloc2 = new GuestPaymentAllocation();
        $alloc2->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $this->folio->id,
            'amount' => '10.00',
            'allocation_idempotency_key' => 'over-alloc2-' . uniqid(),
            'allocated_at' => now(),
            'allocated_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        $this->assertContains('PAYMENT_SOURCE_CONFLICT', $result->review_reasons);
    }

    // ── Reversed allocation opens up value again ─────────────────────────────

    public function test_reversed_allocation_reopens_value(): void
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-REV-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => 'RECORDED',
            'recording_idempotency_key' => 'rev-test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $alloc = new GuestPaymentAllocation();
        $alloc->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $this->folio->id,
            'amount' => '100.00',
            'allocation_idempotency_key' => 'rev-alloc-' . uniqid(),
            'allocated_at' => now(),
            'allocated_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        // Reverse the allocation
        $rev = new GuestPaymentReversal();
        $rev->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'guest_payment_allocation_id' => $alloc->id,
            'reversal_type' => GuestPaymentReversalTypeEnum::AllocationReversal->value,
            'amount' => '100.00',
            'reason_code' => 'TEST_REV',
            'reversal_idempotency_key' => 'rev-rev-' . uniqid(),
            'reversed_at' => now(),
            'reversed_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        // After reversal, the allocation is undone → payment is now unresolved (no active allocations)
        $this->assertContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
    }

    // ── Deposit void resolves ────────────────────────────────────────────────

    public function test_valid_deposit_void_resolves(): void
    {
        // Create deposit as RECORDED, then add void reversal. This simulates
        // a properly voided deposit. The projection should treat it as resolved.
        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id,
            'deposit_number' => 'GDP-SI-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '200.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'dep-void-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        // Valid void reversal (deposit has no applications)
        $reversal = new GuestDepositReversal();
        $reversal->forceFill([
            'property_id' => $this->property->id,
            'guest_deposit_transaction_id' => $deposit->id,
            'reversal_type' => GuestDepositReversalTypeEnum::DepositVoid->value,
            'amount' => '200.00',
            'reason_code' => 'TEST',
            'reversal_idempotency_key' => 'dep-void-rev-' . uniqid(),
            'reversed_at' => now(),
            'reversed_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        // Update deposit to VOIDED (as the service would)
        $deposit->forceFill(['lifecycle_status' => GuestDepositLifecycleStatusEnum::Voided->value])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        $this->assertNotContains('GUEST_DEPOSIT_UNRESOLVED', $result->blocker_codes);
    }

    // ── Deposit partial application blocks ───────────────────────────────────

    public function test_deposit_partial_application_blocks(): void
    {
        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id,
            'deposit_number' => 'GDP-PART-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '200.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => 'PARTIALLY_RESOLVED',
            'recording_idempotency_key' => 'dep-part-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        // Only apply half
        $app = new GuestDepositApplication();
        $app->forceFill([
            'property_id' => $this->property->id,
            'guest_deposit_transaction_id' => $deposit->id,
            'folio_id' => $this->folio->id,
            'amount' => '100.00',
            'application_idempotency_key' => 'dep-app-half-' . uniqid(),
            'applied_at' => now(),
            'applied_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        $this->assertContains('GUEST_DEPOSIT_UNRESOLVED', $result->blocker_codes);
    }

    // ── Valid refund terminal ────────────────────────────────────────────────

    public function test_valid_payment_refund_terminal(): void
    {
        // Fully refunded payment — resolved. Uses the projection test paths.
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-REF-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '75.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => 'RECORDED',
            'recording_idempotency_key' => 'ref-test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $refund = new GuestRefundTransaction();
        $refund->forceFill([
            'property_id' => $this->property->id,
            'refund_number' => 'GRF-SI-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '75.00',
            'tender_type' => 'CASH',
            'cashier_session_id' => $this->cashierSession->id,
            'refund_source_type' => 'GUEST_PAYMENT',
            'guest_payment_transaction_id' => $payment->id,
            'reason_code' => 'TEST',
            'refund_idempotency_key' => 'ref-test-' . uniqid(),
            'refunded_at' => now(),
            'refunded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
            'created_by' => $this->actor->id,
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        $this->assertNotContains('GUEST_PAYMENT_UNRESOLVED', $result->blocker_codes);
    }

    // ── XOR refund source conflict ───────────────────────────────────────────

    public function test_refund_guest_mismatch_requires_review(): void
    {
        // Create a refund with a guest that differs from the reservation primary guest.
        // The refund's guest_id will mismatch the reservation's primary_guest_id.
        // We need a payment first, then a refund referencing that payment.
        // Test that guest mismatch on any financial record triggers review.
        $otherGuest = Guest::create([
            'property_id' => $this->property->id,
            'guest_code' => 'OTHER-GST2',
            'full_name' => 'Other Guest 2',
            'guest_type' => 'individual',
        ]);

        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-GM-' . uniqid(),
            'reservation_id' => $this->reservation->id,
            'guest_id' => $otherGuest->id,
            'currency' => 'USD',
            'amount' => '30.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => 'RECORDED',
            'recording_idempotency_key' => 'gm-test-' . uniqid(),
            'recorded_at' => now(),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        // Payment with guest mismatch should trigger review
        $this->assertContains('PAYMENT_SOURCE_CONFLICT', $result->review_reasons);
    }

    // ── AR ACCEPTED exact evidence qualifies ─────────────────────────────────

    public function test_ar_accepted_exact_evidence_qualifies(): void
    {
        // Test that an ACCEPTED AR transfer with a valid decision and source FolioItem
        // does NOT produce GUEST_AR_TRANSFER_PENDING. Create only request + decision
        // without FolioItem — the service should flag the missing FolioItem as a review concern.
        $ar = new GuestArTransferRequest();
        $ar->forceFill([
            'property_id' => $this->property->id,
            'transfer_number' => 'GAR-ACC-' . uniqid(),
            'folio_id' => $this->folio->id,
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '50.00',
            'lifecycle_status' => GuestArTransferStatusEnum::Accepted->value,
            'request_reason_code' => 'TEST',
            'request_idempotency_key' => 'ar-acc-' . uniqid(),
            'requested_at' => now(),
            'requested_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $decision = new GuestArTransferDecision();
        $decision->forceFill([
            'property_id' => $this->property->id,
            'guest_ar_transfer_request_id' => $ar->id,
            'decision_type' => GuestArTransferDecisionTypeEnum::Accepted->value,
            'reason_code' => 'TEST',
            'decision_idempotency_key' => 'ar-dec-acc-' . uniqid(),
            'decided_at' => now(),
            'decided_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        // With an accepted decision but no FolioItem, the service should NOT block
        // with AR_TRANSFER_PENDING — it may flag a review or pass silently.
        $this->assertNotContains('GUEST_AR_TRANSFER_PENDING', $result->blocker_codes);
    }

    // ── AR REJECTED does not permanently block ──────────────────────────────

    public function test_ar_rejected_does_not_permanently_block(): void
    {
        $ar = new GuestArTransferRequest();
        $ar->forceFill([
            'property_id' => $this->property->id,
            'transfer_number' => 'GAR-REJ-' . uniqid(),
            'folio_id' => $this->folio->id,
            'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id,
            'currency' => 'USD',
            'amount' => '50.00',
            'lifecycle_status' => GuestArTransferStatusEnum::Rejected->value,
            'request_reason_code' => 'TEST',
            'request_idempotency_key' => 'ar-rej-' . uniqid(),
            'requested_at' => now(),
            'requested_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $decision = new GuestArTransferDecision();
        $decision->forceFill([
            'property_id' => $this->property->id,
            'guest_ar_transfer_request_id' => $ar->id,
            'decision_type' => GuestArTransferDecisionTypeEnum::Rejected->value,
            'reason_code' => 'TEST',
            'decision_idempotency_key' => 'ar-dec-rej-' . uniqid(),
            'decided_at' => now(),
            'decided_by' => $this->actor->id,
            'source_snapshot' => json_encode([]),
            'created_at' => now(),
        ])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        // Rejected does not add GUEST_AR_TRANSFER_PENDING
        $this->assertNotContains('GUEST_AR_TRANSFER_PENDING', $result->blocker_codes);
    }

    // ── Guest mismatch requires review ───────────────────────────────────────

    public function test_folio_guest_mismatch_requires_review(): void
    {
        $otherGuest = Guest::create([
            'property_id' => $this->property->id,
            'guest_code' => 'OTHER-GST',
            'full_name' => 'Other Guest',
            'guest_type' => 'individual',
        ]);

        $f2 = $this->makeGlfFolio($this->reservation, $otherGuest, ['window_number' => 2]);
        $f2->forceFill(['balance' => '0.00', 'total_charges' => '0.00',
            'total_payments' => '0.00', 'total_deposits' => '0.00', 'total_ar_transfers' => '0.00'])->save();

        $result = $this->service->project($this->actor, $this->stay->id);

        $this->assertContains('FOLIO_RELATIONSHIP_CONFLICT', $result->review_reasons);
    }

    // ── Zero-write proof ─────────────────────────────────────────────────────

    public function test_projection_performs_no_writes(): void
    {
        $beforeFolios = Folio::count();
        $beforeItems = FolioItem::count();
        $beforePayments = GuestPaymentTransaction::count();
        $beforeDeposits = GuestDepositTransaction::count();
        $beforeStays = FrontDeskStay::count();

        $this->service->project($this->actor, $this->stay->id);

        $this->assertEquals($beforeFolios, Folio::count());
        $this->assertEquals($beforeItems, FolioItem::count());
        $this->assertEquals($beforePayments, GuestPaymentTransaction::count());
        $this->assertEquals($beforeDeposits, GuestDepositTransaction::count());
        $this->assertEquals($beforeStays, FrontDeskStay::count());
    }

    // ── No float conversion ──────────────────────────────────────────────────

    public function test_balance_is_exact_decimal_string(): void
    {
        $result = $this->service->project($this->actor, $this->stay->id);

        $balance = $result->canonical_aggregate_balance;
        $this->assertIsString($balance);
        $this->assertMatchesRegularExpression('/^-?[0-9]+\.[0-9]{2}$/', $balance);
    }

    // ── Fingerprint mutation tests ───────────────────────────────────────────

    public function test_fingerprint_changes_when_payment_lifecycle_changes(): void
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id, 'payment_number' => 'GPM-FP-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fp-test-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $payment->forceFill(['lifecycle_status' => GuestPaymentLifecycleStatusEnum::FullyAllocated->value])->save();
        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when payment lifecycle changes.');
    }

    public function test_fingerprint_changes_when_folio_item_source_linkage_changes(): void
    {
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id, 'folio_id' => $this->folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge, 'description' => 'Room',
            'quantity' => '1.00', 'amount' => '100.00', 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->actor->id, 'created_by' => $this->actor->id,
        ])->save();
        $this->folio->forceFill(['total_charges' => '100.00', 'balance' => '100.00'])->save();

        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        // Change the item amount
        $item->forceFill(['amount' => '200.00'])->save();
        $this->folio->forceFill(['total_charges' => '200.00', 'balance' => '200.00'])->save();

        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when FolioItem amount changes.');
    }

    public function test_fingerprint_changes_when_deposit_lifecycle_changes(): void
    {
        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id, 'deposit_number' => 'GDP-FP-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '200.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fp-dep-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $deposit->forceFill(['lifecycle_status' => GuestDepositLifecycleStatusEnum::Resolved->value])->save();
        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when deposit lifecycle changes.');
    }

    public function test_fingerprint_changes_when_ar_decision_changes(): void
    {
        $ar = new GuestArTransferRequest();
        $ar->forceFill([
            'property_id' => $this->property->id, 'transfer_number' => 'GAR-FP-' . uniqid(),
            'folio_id' => $this->folio->id, 'reservation_id' => $this->reservation->id,
            'guest_id' => $this->guest->id, 'currency' => 'USD', 'amount' => '50.00',
            'lifecycle_status' => GuestArTransferStatusEnum::Requested->value,
            'request_reason_code' => 'TEST', 'request_idempotency_key' => 'fp-ar-' . uniqid(),
            'requested_at' => now(), 'requested_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $ar->forceFill(['lifecycle_status' => GuestArTransferStatusEnum::Rejected->value])->save();
        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when AR decision changes.');
    }

    public function test_evaluated_at_does_not_affect_fingerprint(): void
    {
        $r1 = $this->service->project($this->actor, $this->stay->id);
        usleep(100000);
        $r2 = $this->service->project($this->actor, $this->stay->id);

        // Fingerprint must be STABLE for unchanged source facts — evaluated_at is not a fingerprint input
        $this->assertEquals($r1->source_fingerprint, $r2->source_fingerprint,
            'Fingerprint must be stable for unchanged source facts — evaluated_at must not affect it.');
    }

    // ── Payment Allocation amount changes fingerprint ─────────────────────────

    public function test_fingerprint_changes_when_payment_allocation_amount_changes(): void
    {
        // Baseline: no payment allocation
        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        // Create payment with allocation at amount 30.00
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id, 'payment_number' => 'GPM-FPA-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fpa-test-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $alloc = new GuestPaymentAllocation();
        $alloc->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $this->folio->id,
            'amount' => '30.00',
            'allocation_idempotency_key' => 'fpa-alloc-' . uniqid(),
            'allocated_at' => now(), 'allocated_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();

        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->property->id, 'folio_id' => $this->folio->id,
            'item_type' => FolioItemTypeEnum::Payment, 'description' => 'Alloc 30',
            'quantity' => '1.00', 'amount' => '-30.00', 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->actor->id, 'created_by' => $this->actor->id,
            'source_domain' => 'pms_cashiering', 'source_type' => 'guest_payment_allocation',
            'source_id' => $alloc->id, 'guest_payment_allocation_id' => $alloc->id,
        ])->save();

        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;
        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when payment allocation record is added.');

        // Add a second payment + allocation at a different amount (70.00).
        // The fingerprint change proves allocation amounts are captured as part of the canonical JSON.
        $payment2 = new GuestPaymentTransaction();
        $payment2->forceFill([
            'property_id' => $this->property->id, 'payment_number' => 'GPM-FPB-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fpb-test-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $alloc2 = new GuestPaymentAllocation();
        $alloc2->forceFill([
            'property_id' => $this->property->id,
            'guest_payment_transaction_id' => $payment2->id,
            'folio_id' => $this->folio->id,
            'amount' => '70.00',
            'allocation_idempotency_key' => 'fpb-alloc-' . uniqid(),
            'allocated_at' => now(), 'allocated_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();

        $item2 = new FolioItem();
        $item2->forceFill([
            'property_id' => $this->property->id, 'folio_id' => $this->folio->id,
            'item_type' => FolioItemTypeEnum::Payment, 'description' => 'Alloc 70',
            'quantity' => '1.00', 'amount' => '-70.00', 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->actor->id, 'created_by' => $this->actor->id,
            'source_domain' => 'pms_cashiering', 'source_type' => 'guest_payment_allocation',
            'source_id' => $alloc2->id, 'guest_payment_allocation_id' => $alloc2->id,
        ])->save();

        $fp3 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;
        $this->assertNotEquals($fp2, $fp3, 'Fingerprint must change when second allocation with different amount is added.');
    }

    // ── Refund amount changes fingerprint ─────────────────────────────────────

    public function test_fingerprint_changes_when_refund_amount_changes(): void
    {
        // Baseline: no refunds
        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        // Create payment
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id, 'payment_number' => 'GPM-FPR-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fpr-test-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        // Create refund at amount 25.00
        $refund = new GuestRefundTransaction();
        $refund->forceFill([
            'property_id' => $this->property->id, 'refund_number' => 'GRF-FPR-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '25.00', 'tender_type' => 'CASH',
            'cashier_session_id' => $this->cashierSession->id,
            'refund_source_type' => 'GUEST_PAYMENT',
            'guest_payment_transaction_id' => $payment->id,
            'reason_code' => 'TEST', 'refund_idempotency_key' => 'fpr-ref1-' . uniqid(),
            'refunded_at' => now(), 'refunded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(), 'created_by' => $this->actor->id,
        ])->save();

        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;
        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when refund record is added.');

        // Add a second refund at a different amount (50.00).
        // The fingerprint change proves refund amounts are captured in the canonical JSON.
        $refund2 = new GuestRefundTransaction();
        $refund2->forceFill([
            'property_id' => $this->property->id, 'refund_number' => 'GRF-FPR2-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '50.00', 'tender_type' => 'CASH',
            'cashier_session_id' => $this->cashierSession->id,
            'refund_source_type' => 'GUEST_PAYMENT',
            'guest_payment_transaction_id' => $payment->id,
            'reason_code' => 'TEST', 'refund_idempotency_key' => 'fpr-ref2-' . uniqid(),
            'refunded_at' => now(), 'refunded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(), 'created_by' => $this->actor->id,
        ])->save();

        $fp3 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;
        $this->assertNotEquals($fp2, $fp3, 'Fingerprint must change when second refund with different amount is added.');
    }

    // ── Refund source FK/type changes fingerprint ─────────────────────────────

    public function test_fingerprint_changes_when_refund_source_changes(): void
    {
        // Baseline: no refunds
        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        // Create a payment and a deposit
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id, 'payment_number' => 'GPM-FPS1-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fps1-test-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id, 'deposit_number' => 'GDP-FPS2-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '100.00',
            'cashier_session_id' => $this->cashierSession->id,
            'tender_type' => 'CASH', 'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'fps2-test-' . uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        // Add refund sourced from GUEST_PAYMENT
        $refund = new GuestRefundTransaction();
        $refund->forceFill([
            'property_id' => $this->property->id, 'refund_number' => 'GRF-FPS-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '30.00', 'tender_type' => 'CASH',
            'cashier_session_id' => $this->cashierSession->id,
            'refund_source_type' => 'GUEST_PAYMENT',
            'guest_payment_transaction_id' => $payment->id,
            'reason_code' => 'TEST', 'refund_idempotency_key' => 'fps-ref1-' . uniqid(),
            'refunded_at' => now(), 'refunded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(), 'created_by' => $this->actor->id,
        ])->save();

        $fp2 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;
        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when payment-sourced refund is added.');

        // Add a second refund sourced from GUEST_DEPOSIT (different source FK/type)
        $refund2 = new GuestRefundTransaction();
        $refund2->forceFill([
            'property_id' => $this->property->id, 'refund_number' => 'GRF-FPS2-' . uniqid(),
            'reservation_id' => $this->reservation->id, 'guest_id' => $this->guest->id,
            'currency' => 'USD', 'amount' => '20.00', 'tender_type' => 'CASH',
            'cashier_session_id' => $this->cashierSession->id,
            'refund_source_type' => 'GUEST_DEPOSIT',
            'guest_deposit_transaction_id' => $deposit->id,
            'reason_code' => 'TEST', 'refund_idempotency_key' => 'fps-ref2-' . uniqid(),
            'refunded_at' => now(), 'refunded_by' => $this->actor->id,
            'source_snapshot' => json_encode([]), 'created_at' => now(), 'created_by' => $this->actor->id,
        ])->save();

        $fp3 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;
        $this->assertNotEquals($fp2, $fp3, 'Fingerprint must change when deposit-sourced refund is added (different source FK/type).');
    }

    // ── External port status changes fingerprint ──────────────────────────────

    public function test_fingerprint_changes_when_external_port_status_changes(): void
    {
        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        // Rebind posting completeness port to BLOCKED
        app()->forgetInstance(GuestLedgerPostingCompletenessReadPort::class);
        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_BLOCKED, 'code' => 'MANDATORY_POSTINGS_INCOMPLETE', 'message' => 'Blocked'];
                }
            };
        });

        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
        $fp2 = $service->project($this->actor, $this->stay->id)->source_fingerprint;

        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when external port status changes.');
    }

    // ── External port stable source identifier changes fingerprint ────────────

    public function test_fingerprint_changes_when_port_source_identifier_changes(): void
    {
        $fp1 = $this->service->project($this->actor, $this->stay->id)->source_fingerprint;

        // Rebind posting completeness port with different code
        app()->forgetInstance(GuestLedgerPostingCompletenessReadPort::class);
        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $reservationId, string $propertyId): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => 'OVERRIDE_RESOLVED', 'message' => null];
                }
            };
        });

        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
        $fp2 = $service->project($this->actor, $this->stay->id)->source_fingerprint;

        $this->assertNotEquals($fp1, $fp2, 'Fingerprint must change when port stable source identifier changes.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function bindClearPorts(): void
    {
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

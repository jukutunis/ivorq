<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutObligationStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutObligationProjectionService;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestRefundSourceTypeEnum;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestDepositLifecycleService;
use Modules\Operations\PMS\Services\GuestPaymentLifecycleService;
use Modules\Operations\PMS\Services\GuestRefundLifecycleService;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestDepositRefundArData;
use Tests\PostgresTestCase;

class GeneralCashierCheckoutObligationProjectionTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestDepositRefundArData;

    private GeneralCashierCheckoutObligationProjectionService $service;
    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private User $otherActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpGlfCFixture();

        $this->company = $this->glfCompany;
        $this->property = $this->glfProperty;
        $this->otherProperty = $this->glfOtherProperty;
        $this->actor = $this->glfActor;
        $this->otherActor = $this->glfOtherActor;

        Permission::firstOrCreate([
            'name' => GeneralCashierCheckoutObligationProjectionService::VIEW_PERMISSION,
            'guard_name' => 'web',
        ]);
        foreach ([
            GuestPaymentLifecycleService::VOID_PERMISSION,
            GuestPaymentLifecycleService::REVERSAL_PERMISSION,
            GuestRefundLifecycleService::RECORD_PERMISSION,
            GuestDepositLifecycleService::VOID_PERMISSION,
            GuestDepositLifecycleService::REVERSE_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            GeneralCashierCheckoutObligationProjectionService::VIEW_PERMISSION,
            GuestPaymentLifecycleService::VOID_PERMISSION,
            GuestPaymentLifecycleService::REVERSAL_PERMISSION,
            GuestRefundLifecycleService::RECORD_PERMISSION,
            GuestDepositLifecycleService::VOID_PERMISSION,
            GuestDepositLifecycleService::REVERSE_PERMISSION,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session([
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
        ]);
        auth()->login($this->actor);
        $this->actingAs($this->actor);

        $this->service = app(GeneralCashierCheckoutObligationProjectionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_clear_when_no_authoritative_cashier_obligation_is_linked(): void
    {
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationProjectionService::PROJECTION_VERSION, $projection->projection_version);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationClear, $projection->status);
        $this->assertSame($this->property->id, $projection->property_id);
        $this->assertSame($stay->id, $projection->front_desk_stay_id);
        $this->assertSame($reservation->id, $projection->reservation_id);
        $this->assertSame($reservation->primary_guest_id, $projection->guest_id);
        $this->assertSame([], $projection->related_guest_payment_transaction_ids);
        $this->assertSame([], $projection->related_cashier_session_ids);
        $this->assertSame('NO_AUTHORITATIVE_CASHIER_OBLIGATIONS', $projection->markers['cashier_obligation_scope_marker']);
        $this->assertSame('CASHIER_ACCOUNTABILITY_CLEAR', $projection->markers['cashier_accountability_marker']);
        $this->assertNotEmpty($projection->evaluated_at);
        $this->assertNotEmpty($projection->source_fingerprint);
        $this->assertArrayHasKey('source_identifiers', get_object_vars($projection));
    }

    public function test_open_cash_payment_session_blocks_checkout_obligation(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $payment = $this->payment($reservation, $guest, $session);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $projection->status);
        $this->assertContains('CASHIER_SESSION_OPEN', $projection->blocker_codes);
        $this->assertContains($payment->id, $projection->related_guest_payment_transaction_ids);
        $this->assertContains($session->id, $projection->related_cashier_session_ids);
        $this->assertSame('CASHIER_ACCOUNTABILITY_BLOCKED', $projection->markers['cashier_accountability_marker']);
    }

    public function test_closed_linked_payment_fails_closed_when_accountability_evidence_is_unavailable(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::CLOSED);
        $this->payment($reservation, $guest, $session);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable, $projection->status);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $projection->evidence_unavailable_codes);
        $this->assertSame('CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $projection->markers['cashier_accountability_marker']);
    }

    public function test_production_payment_recorded_open_then_session_closed_is_evidence_unavailable_not_snapshot_conflict(): void
    {
        [$stay, $reservation] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);

        $this->paymentService->recordCashPayment($this->actor, $reservation->id, $session->id, '10.00', 'gc-a1-prod-payment');
        $this->closeSession($session);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable, $projection->status);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $projection->evidence_unavailable_codes);
        $this->assertNotContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons);
    }

    public function test_production_deposit_recorded_open_then_session_closed_is_evidence_unavailable_not_snapshot_conflict(): void
    {
        [$stay, $reservation] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);

        $this->depositService->recordCashDeposit($this->actor, $reservation->id, $session->id, '10.00', 'gc-a1-prod-deposit');
        $this->closeSession($session);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable, $projection->status);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $projection->evidence_unavailable_codes);
        $this->assertNotContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons);
    }

    public function test_conflicting_source_snapshot_requires_review(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session, [
            'cashier_session_id' => $session->id,
            'cashier_user_id' => (string) Str::ulid(),
            'cashier_session_status' => 'OPEN',
        ]);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons);
        $this->assertSame('CASHIER_ACCOUNTABILITY_REVIEW_REQUIRED', $projection->markers['cashier_accountability_marker']);
    }

    public function test_snapshot_session_identity_conflict_requires_review(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session, [
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_user_id' => $session->cashier_user_id,
            'cashier_session_status' => 'OPEN',
        ]);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons);
    }

    public function test_deposit_and_refund_cash_sources_are_included_as_authoritative_session_obligations(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $deposit = $this->deposit($reservation, $guest, $session);
        $refund = $this->refund($reservation, $guest, $session, $deposit);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $projection->status);
        $this->assertContains($session->id, $projection->related_cashier_session_ids);
        $this->assertContains($deposit->id, $projection->source_identifiers['related_guest_deposit_transaction_ids']);
        $this->assertContains($refund->id, $projection->source_identifiers['related_guest_refund_transaction_ids']);
    }

    public function test_payment_lifecycle_matrix_excludes_voided_and_fully_allocated_sources_but_reincludes_reversed_allocation(): void
    {
        [$stay, $reservation] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);

        $voided = $this->paymentService->recordCashPayment($this->actor, $reservation->id, $session->id, '10.00', 'gc-a1-pay-void');
        $this->confirmGlfC(GuestPaymentLifecycleService::VOID_CONFIRMATION_INTENT);
        $this->paymentService->voidPayment($this->actor, $voided->id, 'VOIDED', 'gc-a1-pay-void-rev');
        $fullyAllocated = $this->paymentService->recordCashPayment($this->actor, $reservation->id, $session->id, '20.00', 'gc-a1-pay-alloc');
        $folio = $this->makeGlfFolio($reservation, Guest::findOrFail($reservation->primary_guest_id));
        $allocation = $this->paymentService->allocatePayment($this->actor, $fullyAllocated->id, $folio->id, '20.00', 'gc-a1-pay-alloc-full');

        $clearProjection = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationClear, $clearProjection->status);
        $this->assertNotContains($voided->id, $clearProjection->related_guest_payment_transaction_ids);
        $this->assertNotContains($fullyAllocated->id, $clearProjection->related_guest_payment_transaction_ids);

        $this->confirmGlfC(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);
        $this->paymentService->reverseAllocation($this->actor, $allocation->id, 'REVERSAL', 'gc-a1-pay-alloc-reverse');

        $blockedProjection = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $blockedProjection->status);
        $this->assertContains($fullyAllocated->id, $blockedProjection->related_guest_payment_transaction_ids);
    }

    public function test_deposit_lifecycle_matrix_excludes_voided_and_resolved_sources_but_reincludes_application_reversal(): void
    {
        [$stay, $reservation] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);

        $voided = $this->depositService->recordCashDeposit($this->actor, $reservation->id, $session->id, '10.00', 'gc-a1-dep-void');
        $this->confirmGlfC(GuestDepositLifecycleService::VOID_INTENT);
        $this->depositService->voidDeposit($this->actor, $voided->id, 'VOIDED', 'gc-a1-dep-void-rev');
        $resolved = $this->depositService->recordCashDeposit($this->actor, $reservation->id, $session->id, '20.00', 'gc-a1-dep-app');
        $folio = $this->makeGlfFolio($reservation, Guest::findOrFail($reservation->primary_guest_id));
        $application = $this->depositService->applyDeposit($this->actor, $resolved->id, $folio->id, '20.00', 'gc-a1-dep-app-full');

        $clearProjection = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationClear, $clearProjection->status);
        $this->assertNotContains($voided->id, $clearProjection->source_identifiers['related_guest_deposit_transaction_ids']);
        $this->assertNotContains($resolved->id, $clearProjection->source_identifiers['related_guest_deposit_transaction_ids']);

        $this->confirmGlfC(GuestDepositLifecycleService::REVERSE_INTENT);
        $this->depositService->reverseDepositApplication($this->actor, $application->id, 'REVERSAL', 'gc-a1-dep-app-reverse');

        $blockedProjection = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $blockedProjection->status);
        $this->assertContains($resolved->id, $blockedProjection->source_identifiers['related_guest_deposit_transaction_ids']);
    }

    public function test_payment_and_deposit_sourced_refunds_are_relevant_without_double_counting_resolved_sources(): void
    {
        [$stay, $reservation] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $payment = $this->paymentService->recordCashPayment($this->actor, $reservation->id, $session->id, '10.00', 'gc-a1-ref-pay-source');
        $deposit = $this->depositService->recordCashDeposit($this->actor, $reservation->id, $session->id, '10.00', 'gc-a1-ref-dep-source');

        $this->confirmGlfC(GuestRefundLifecycleService::CONFIRMATION_INTENT);
        $paymentRefund = $this->refundService->recordCashRefund($this->actor, GuestRefundSourceTypeEnum::GuestPayment->value, $payment->id, $session->id, '10.00', 'PAY_REF', 'gc-a1-ref-pay');
        $this->confirmGlfC(GuestRefundLifecycleService::CONFIRMATION_INTENT);
        $depositRefund = $this->refundService->recordCashRefund($this->actor, GuestRefundSourceTypeEnum::GuestDeposit->value, $deposit->id, $session->id, '10.00', 'DEP_REF', 'gc-a1-ref-dep');

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $projection->status);
        $this->assertNotContains($payment->id, $projection->related_guest_payment_transaction_ids);
        $this->assertNotContains($deposit->id, $projection->source_identifiers['related_guest_deposit_transaction_ids']);
        $expectedRefundIds = [$paymentRefund->id, $depositRefund->id];
        $actualRefundIds = $projection->source_identifiers['related_guest_refund_transaction_ids'];
        sort($expectedRefundIds);
        sort($actualRefundIds);
        $this->assertSame($expectedRefundIds, $actualRefundIds);
    }

    public function test_mixed_state_precedence_prefers_review_then_unavailable_then_blocked(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $openSession = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $closedCashier = $this->userWithoutPermission();
        $closedSession = $this->cashierSession(CashierSessionStatusEnum::CLOSED, $closedCashier);
        $closedSession->forceFill(['closed_by' => null])->save();
        $this->payment($reservation, $guest, $openSession);
        $this->payment($reservation, $guest, $closedSession);
        $this->payment($reservation, $guest, $openSession, [
            'cashier_session_id' => $openSession->id,
            'cashier_user_id' => (string) Str::ulid(),
            'cashier_session_status' => 'OPEN',
        ]);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons);
        $this->assertContains('CASHIER_SESSION_CLOSE_EVIDENCE_UNAVAILABLE', $projection->evidence_unavailable_codes);
        $this->assertContains('CASHIER_SESSION_OPEN', $projection->blocker_codes);
    }

    public function test_payment_snapshot_immutable_identity_mismatches_require_review(): void
    {
        foreach ([
            'reservation_id' => (string) Str::ulid(),
            'guest_id' => (string) Str::ulid(),
            'currency' => 'EUR',
            'tender_type' => 'BANK',
            'opened_by' => $this->otherActor->id,
            'opened_at' => Carbon::parse('2026-07-14 07:59:00')->toISOString(),
        ] as $field => $value) {
            [$stay, $reservation, $guest] = $this->stayTriplet();
            $session = $this->cashierSession(CashierSessionStatusEnum::CLOSED, $this->userWithoutPermission());
            $snapshot = $this->cashSourceSnapshot($session, $reservation, $guest);
            $snapshot[$field] = $value;
            $this->payment($reservation, $guest, $session, $snapshot);

            $projection = $this->service->project($this->actor, $stay->id);

            $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status, $field);
            $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons, $field);
            $this->closeSession($session);
        }
    }

    public function test_deposit_snapshot_immutable_identity_mismatches_require_review(): void
    {
        foreach ([
            'reservation_id' => (string) Str::ulid(),
            'guest_id' => (string) Str::ulid(),
            'currency' => 'EUR',
            'tender_type' => 'BANK',
        ] as $field => $value) {
            [$stay, $reservation, $guest] = $this->stayTriplet();
            $session = $this->cashierSession(CashierSessionStatusEnum::CLOSED, $this->userWithoutPermission());
            $snapshot = $this->cashSourceSnapshot($session, $reservation, $guest);
            $snapshot[$field] = $value;
            $this->deposit($reservation, $guest, $session, $snapshot);

            $projection = $this->service->project($this->actor, $stay->id);

            $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status, $field);
            $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons, $field);
            $this->closeSession($session);
        }
    }

    public function test_refund_snapshot_source_identity_and_amount_mismatches_require_review(): void
    {
        foreach ([
            'source_type' => GuestRefundSourceTypeEnum::GuestPayment->value,
            'source_id' => (string) Str::ulid(),
            'source_number' => 'GRF-BAD-SOURCE',
            'source_amount' => '99.99',
            'available_before_refund' => '8.99',
            'reservation_id' => (string) Str::ulid(),
            'guest_id' => (string) Str::ulid(),
            'currency' => 'EUR',
            'amount' => '2.00',
            'reason_code' => 'BAD_REASON',
        ] as $field => $value) {
            [$stay, $reservation, $guest] = $this->stayTriplet();
            $session = $this->cashierSession(CashierSessionStatusEnum::CLOSED, $this->userWithoutPermission());
            $deposit = $this->deposit($reservation, $guest, $session);
            $snapshot = $this->refundSnapshot($session, $deposit, '1.00', 'GC_A1_TEST');
            $snapshot[$field] = $value;
            $this->refund($reservation, $guest, $session, $deposit, $snapshot);

            $projection = $this->service->project($this->actor, $stay->id);

            $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status, $field);
            $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons, $field);
            $this->closeSession($session);
        }
    }

    public function test_excluded_voided_payment_and_resolved_deposit_still_validate_source_snapshots(): void
    {
        $sharedSession = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        [$paymentStay, $paymentReservation] = $this->stayTriplet();
        $voided = $this->paymentService->recordCashPayment($this->actor, $paymentReservation->id, $sharedSession->id, '10.00', 'gc-a1-excluded-void-snapshot');
        $this->confirmGlfC(GuestPaymentLifecycleService::VOID_CONFIRMATION_INTENT);
        $this->paymentService->voidPayment($this->actor, $voided->id, 'VOIDED', 'gc-a1-excluded-void-reversal');
        $snapshot = $voided->fresh()->source_snapshot;
        $snapshot['reservation_id'] = (string) Str::ulid();
        $this->forceUpdateJson('guest_payment_transactions', $voided->id, 'source_snapshot', $snapshot);

        $paymentProjection = $this->service->project($this->actor, $paymentStay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $paymentProjection->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $paymentProjection->review_reasons);

        [$depositStay, $depositReservation] = $this->stayTriplet();
        $resolved = $this->depositService->recordCashDeposit($this->actor, $depositReservation->id, $sharedSession->id, '20.00', 'gc-a1-excluded-dep-snapshot');
        $folio = $this->makeGlfFolio($depositReservation, Guest::findOrFail($depositReservation->primary_guest_id));
        $this->depositService->applyDeposit($this->actor, $resolved->id, $folio->id, '20.00', 'gc-a1-excluded-dep-application');
        $snapshot = $resolved->fresh()->source_snapshot;
        $snapshot['guest_id'] = (string) Str::ulid();
        $this->forceUpdateJson('guest_deposit_transactions', $resolved->id, 'source_snapshot', $snapshot);

        $depositProjection = $this->service->project($this->actor, $depositStay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $depositProjection->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $depositProjection->review_reasons);
    }

    public function test_invalid_void_and_reversal_evidence_requires_review_and_cannot_clear(): void
    {
        $sharedSession = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        [$voidStay, $voidReservation] = $this->stayTriplet();
        $voided = $this->paymentService->recordCashPayment($this->actor, $voidReservation->id, $sharedSession->id, '10.00', 'gc-a1-bad-void-amount');
        $this->confirmGlfC(GuestPaymentLifecycleService::VOID_CONFIRMATION_INTENT);
        $paymentVoid = $this->paymentService->voidPayment($this->actor, $voided->id, 'VOIDED', 'gc-a1-bad-void-reversal');
        $this->forceUpdateColumn('guest_payment_reversals', $paymentVoid->id, 'amount', '9.99');

        $voidProjection = $this->service->project($this->actor, $voidStay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $voidProjection->status);
        $this->assertContains('CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT', $voidProjection->review_reasons);

        [$allocationStay, $allocationReservation] = $this->stayTriplet();
        $payment = $this->paymentService->recordCashPayment($this->actor, $allocationReservation->id, $sharedSession->id, '20.00', 'gc-a1-bad-allocation-reversal');
        $folio = $this->makeGlfFolio($allocationReservation, Guest::findOrFail($allocationReservation->primary_guest_id));
        $allocation = $this->paymentService->allocatePayment($this->actor, $payment->id, $folio->id, '20.00', 'gc-a1-bad-allocation');
        $this->confirmGlfC(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);
        $allocationReversal = $this->paymentService->reverseAllocation($this->actor, $allocation->id, 'REVERSAL', 'gc-a1-bad-allocation-reversal-row');
        $snapshot = $allocationReversal->source_snapshot;
        $snapshot['folio_id'] = (string) Str::ulid();
        $this->forceUpdateJson('guest_payment_reversals', $allocationReversal->id, 'source_snapshot', $snapshot);

        $allocationProjection = $this->service->project($this->actor, $allocationStay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $allocationProjection->status);
        $this->assertContains('CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT', $allocationProjection->review_reasons);

        [$depositVoidStay, $depositVoidReservation] = $this->stayTriplet();
        $deposit = $this->depositService->recordCashDeposit($this->actor, $depositVoidReservation->id, $sharedSession->id, '10.00', 'gc-a1-bad-dep-void');
        $this->confirmGlfC(GuestDepositLifecycleService::VOID_INTENT);
        $depositVoid = $this->depositService->voidDeposit($this->actor, $deposit->id, 'VOIDED', 'gc-a1-bad-dep-void-row');
        $this->forceUpdateColumn('guest_deposit_reversals', $depositVoid->id, 'amount', '9.99');

        $depositVoidProjection = $this->service->project($this->actor, $depositVoidStay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $depositVoidProjection->status);
        $this->assertContains('CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT', $depositVoidProjection->review_reasons);

        [$applicationStay, $applicationReservation] = $this->stayTriplet();
        $applicationDeposit = $this->depositService->recordCashDeposit($this->actor, $applicationReservation->id, $sharedSession->id, '20.00', 'gc-a1-bad-dep-app-reversal');
        $applicationFolio = $this->makeGlfFolio($applicationReservation, Guest::findOrFail($applicationReservation->primary_guest_id));
        $application = $this->depositService->applyDeposit($this->actor, $applicationDeposit->id, $applicationFolio->id, '20.00', 'gc-a1-bad-dep-app');
        $this->confirmGlfC(GuestDepositLifecycleService::REVERSE_INTENT);
        $applicationReversal = $this->depositService->reverseDepositApplication($this->actor, $application->id, 'REVERSAL', 'gc-a1-bad-dep-app-reversal-row');
        $snapshot = $applicationReversal->source_snapshot;
        $snapshot['application_id'] = (string) Str::ulid();
        $this->forceUpdateJson('guest_deposit_reversals', $applicationReversal->id, 'source_snapshot', $snapshot);

        $applicationProjection = $this->service->project($this->actor, $applicationStay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $applicationProjection->status);
        $this->assertContains('CASHIER_OBLIGATION_RELEVANCE_SOURCE_CONFLICT', $applicationProjection->review_reasons);
    }

    public function test_authorization_occurs_before_stay_lookup(): void
    {
        $unauthorized = $this->userWithoutPermission();
        auth()->login($unauthorized);
        $this->actingAs($unauthorized);

        $this->expectException(AuthorizationException::class);
        $this->service->project($unauthorized, (string) Str::ulid());
    }

    public function test_missing_active_company_is_rejected_before_stay_lookup(): void
    {
        session()->forget('active_company_id');

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_unknown_active_company_is_rejected_before_stay_lookup(): void
    {
        session(['active_company_id' => (string) Str::ulid()]);

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_inactive_active_company_is_rejected_before_stay_lookup(): void
    {
        $this->company->forceFill(['is_active' => false])->save();

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_cross_company_property_context_is_rejected_before_stay_lookup(): void
    {
        $otherCompany = Company::create([
            'name' => 'GC A1 Other Company',
            'slug' => 'gc-a1-other-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        session(['active_company_id' => $otherCompany->id]);

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_inactive_property_context_is_rejected_before_stay_lookup(): void
    {
        $this->property->forceFill(['is_active' => false])->save();

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_inactive_actor_is_rejected_before_stay_lookup(): void
    {
        $this->actor->forceFill(['is_active' => false])->save();

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_inactive_property_membership_is_rejected_before_stay_lookup(): void
    {
        $this->actor->properties()->updateExistingPivot($this->property->id, ['status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_unknown_and_cross_property_stays_are_non_disclosing_after_authorization(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_cross_property_stay_matches_unknown_stay_denial(): void
    {
        $reservation = $this->makeGlfReservation($this->otherProperty);
        $stay = $this->makeStay($reservation, $this->otherProperty);

        $this->expectException(NotFoundException::class);
        $this->service->project($this->actor, $stay->id);
    }

    public function test_actor_mismatch_is_rejected(): void
    {
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->otherActor, $stay->id);
    }

    public function test_fingerprint_excludes_evaluated_at_and_changes_with_source_facts(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);

        Carbon::setTestNow(Carbon::parse('2026-07-14 10:00:00'));
        $first = $this->service->project($this->actor, $stay->id);

        Carbon::setTestNow(Carbon::parse('2026-07-14 10:05:00'));
        $second = $this->service->project($this->actor, $stay->id);

        $this->assertNotSame($first->evaluated_at, $second->evaluated_at);
        $this->assertSame($first->source_fingerprint, $second->source_fingerprint);

        $session->forceFill([
            'status' => CashierSessionStatusEnum::CLOSED->value,
            'closed_at' => Carbon::parse('2026-07-14 10:06:00'),
            'closed_by' => $this->actor->id,
        ])->save();

        $third = $this->service->project($this->actor, $stay->id);
        $this->assertNotSame($first->source_fingerprint, $third->source_fingerprint);
    }

    public function test_projection_is_zero_write_across_source_tables(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);
        $before = $this->sourceCounts();

        $this->service->project($this->actor, $stay->id);

        $this->assertSame($before, $this->sourceCounts());
    }

    public function test_projection_requires_top_level_read_transaction(): void
    {
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutObligationProjectionService::STABLE_ERROR_NESTED_TX);

        DB::transaction(fn () => $this->service->project($this->actor, $stay->id));
    }

    public function test_repeatable_read_snapshot_remains_coherent_during_concurrent_session_mutation(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);

        $preMutation = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $preMutation->status);

        $results = $this->spawnConcurrencyWorkers($stay, $session);
        $projectionWorker = $results[0];
        $mutationWorker = $results[1];

        $this->assertArrayNotHasKey('error', $projectionWorker, $projectionWorker['error'] ?? '');
        $this->assertArrayNotHasKey('error', $mutationWorker, $mutationWorker['error'] ?? '');
        $this->assertSame(0, $projectionWorker['_exit_code']);
        $this->assertSame(0, $mutationWorker['_exit_code']);
        $this->assertNotSame($projectionWorker['php_pid'], $mutationWorker['php_pid']);
        $this->assertNotSame($projectionWorker['pg_backend_pid'], $mutationWorker['pg_backend_pid']);
        $this->assertTrue($mutationWorker['mutator_executed']);
        $this->assertTrue($projectionWorker['handshake']['both_workers_started']);
        $this->assertTrue($projectionWorker['handshake']['projection_transaction_entered']);
        $this->assertSame('repeatable read', $projectionWorker['handshake']['projection_transaction_isolation']);
        $this->assertSame('on', $projectionWorker['handshake']['projection_transaction_read_only']);
        $this->assertTrue($projectionWorker['handshake']['projection_first_source_read_completed']);
        $this->assertTrue($mutationWorker['handshake']['mutator_observed_first_source_read_completed']);
        $this->assertTrue($mutationWorker['handshake']['mutation_committed']);
        $this->assertTrue($projectionWorker['handshake']['projection_observed_mutation_committed_barrier']);

        $this->assertSame($preMutation->status->value, $projectionWorker['status']);
        $this->assertSame($preMutation->source_fingerprint, $projectionWorker['source_fingerprint']);
        $this->assertSame($preMutation->blocker_codes, $projectionWorker['blocker_codes']);

        $postMutation = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable, $postMutation->status);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $postMutation->evidence_unavailable_codes);
        $this->assertNotContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $postMutation->review_reasons);
        $this->assertNotSame($postMutation->source_fingerprint, $projectionWorker['source_fingerprint']);
    }

    public function test_concurrency_worker_fails_hard_when_mutation_barrier_is_not_reached(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);

        $result = $this->spawnProjectionWorkerWithoutMutator($stay, $session);

        $this->assertNotSame(0, $result['_exit_code']);
        $this->assertStringContainsString('GC_A1_BARRIER_TIMEOUT:ready-w1', $result['error']);
    }

    private function stayTriplet(): array
    {
        $reservation = $this->makeGlfReservation();
        $guest = Guest::withoutGlobalScopes()->findOrFail($reservation->primary_guest_id);
        $stay = $this->makeStay($reservation);

        return [$stay, $reservation, $guest];
    }

    private function makeStay(Reservation $reservation, ?Property $property = null): FrontDeskStay
    {
        $property = $property ?? $this->property;

        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->primary_guest_id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $stay->fresh();
    }

    private function cashierSession(CashierSessionStatusEnum $status, ?User $cashier = null): CashierSession
    {
        $cashier = $cashier ?? $this->actor;

        $session = new CashierSession();
        $session->forceFill([
            'property_id' => $this->property->id,
            'cashier_user_id' => $cashier->id,
            'status' => $status->value,
            'opened_at' => Carbon::parse('2026-07-14 08:00:00'),
            'opened_by' => $cashier->id,
            'closed_at' => $status === CashierSessionStatusEnum::CLOSED ? Carbon::parse('2026-07-14 09:00:00') : null,
            'closed_by' => $status === CashierSessionStatusEnum::CLOSED ? $cashier->id : null,
        ])->save();

        return $session->fresh();
    }

    private function closeSession(CashierSession $session): CashierSession
    {
        $session->forceFill([
            'status' => CashierSessionStatusEnum::CLOSED->value,
            'closed_at' => Carbon::parse('2026-07-14 09:00:00'),
            'closed_by' => $session->cashier_user_id,
        ])->save();

        return $session->fresh();
    }

    private function payment(Reservation $reservation, Guest $guest, CashierSession $session, array $snapshot = []): GuestPaymentTransaction
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-GCA1-' . Str::upper(Str::random(6)),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '0.01',
            'tender_type' => 'CASH',
            'cashier_session_id' => $session->id,
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'gc-a1-payment-' . Str::ulid(),
            'recorded_at' => Carbon::parse('2026-07-14 08:10:00'),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => $snapshot ?: $this->cashSourceSnapshot($session, $reservation, $guest),
            'created_at' => now(),
            'updated_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $payment->fresh();
    }

    private function deposit(Reservation $reservation, Guest $guest, CashierSession $session, array $snapshot = []): GuestDepositTransaction
    {
        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id,
            'deposit_number' => 'GDP-GCA1-' . Str::upper(Str::random(6)),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '10.00',
            'tender_type' => 'CASH',
            'cashier_session_id' => $session->id,
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'gc-a1-deposit-' . Str::ulid(),
            'recorded_at' => Carbon::parse('2026-07-14 08:20:00'),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => $snapshot ?: $this->cashSourceSnapshot($session, $reservation, $guest),
            'created_at' => now(),
            'updated_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $deposit->fresh();
    }

    private function refund(
        Reservation $reservation,
        Guest $guest,
        CashierSession $session,
        GuestDepositTransaction $deposit,
        array $snapshot = []
    ): GuestRefundTransaction {
        $refund = new GuestRefundTransaction();
        $refund->forceFill([
            'property_id' => $this->property->id,
            'refund_number' => 'GRF-GCA1-' . Str::upper(Str::random(6)),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '1.00',
            'tender_type' => 'CASH',
            'cashier_session_id' => $session->id,
            'refund_source_type' => 'GUEST_DEPOSIT',
            'guest_payment_transaction_id' => null,
            'guest_deposit_transaction_id' => $deposit->id,
            'reason_code' => 'GC_A1_TEST',
            'refund_idempotency_key' => 'gc-a1-refund-' . Str::ulid(),
            'refunded_at' => Carbon::parse('2026-07-14 08:30:00'),
            'refunded_by' => $this->actor->id,
            'source_snapshot' => $snapshot ?: $this->refundSnapshot($session, $deposit, '1.00', 'GC_A1_TEST'),
            'created_at' => now(),
            'created_by' => $this->actor->id,
        ])->save();

        $deposit->forceFill([
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::PartiallyResolved->value,
            'updated_by' => $this->actor->id,
        ])->save();

        return $refund->fresh();
    }

    private function cashSourceSnapshot(CashierSession $session, Reservation $reservation, Guest $guest): array
    {
        return [
            'cashier_session_id' => $session->id,
            'cashier_user_id' => $session->cashier_user_id,
            'cashier_session_status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => $session->opened_at?->toISOString(),
            'opened_by' => $session->opened_by,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'tender_type' => 'CASH',
        ];
    }

    private function refundSnapshot(
        CashierSession $session,
        GuestDepositTransaction $deposit,
        string $amount,
        string $reasonCode
    ): array {
        return [
            'source_type' => GuestRefundSourceTypeEnum::GuestDeposit->value,
            'source_id' => $deposit->id,
            'source_number' => $deposit->deposit_number,
            'source_amount' => $this->amount($deposit->amount),
            'available_before_refund' => $this->amount($deposit->amount),
            'cashier_session_id' => $session->id,
            'cashier_user_id' => $session->cashier_user_id,
            'reservation_id' => $deposit->reservation_id,
            'guest_id' => $deposit->guest_id,
            'currency' => $deposit->currency,
            'amount' => $this->amount($amount),
            'reason_code' => $reasonCode,
        ];
    }

    private function forceUpdateJson(string $table, string $id, string $column, array $value): void
    {
        $this->withUserTriggersDisabled($table, fn () => DB::table($table)
            ->where('id', $id)
            ->update([$column => json_encode($value)]));
    }

    private function forceUpdateColumn(string $table, string $id, string $column, string $value): void
    {
        $this->withUserTriggersDisabled($table, fn () => DB::table($table)
            ->where('id', $id)
            ->update([$column => $value]));
    }

    private function withUserTriggersDisabled(string $table, callable $callback): void
    {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
        try {
            $callback();
        } finally {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
        }
    }

    private function amount(mixed $value): string
    {
        return bcadd((string) $value, '0.00', 2);
    }

    private function userWithoutPermission(): User
    {
        $user = User::create([
            'name' => 'GC A1 No Permission',
            'email' => 'gc-a1-no-perm-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function sourceCounts(): array
    {
        return [
            'front_desk_stays' => DB::table('front_desk_stays')->count(),
            'guest_payment_transactions' => DB::table('guest_payment_transactions')->count(),
            'guest_payment_allocations' => DB::table('guest_payment_allocations')->count(),
            'guest_payment_reversals' => DB::table('guest_payment_reversals')->count(),
            'guest_deposit_transactions' => DB::table('guest_deposit_transactions')->count(),
            'guest_deposit_applications' => DB::table('guest_deposit_applications')->count(),
            'guest_deposit_reversals' => DB::table('guest_deposit_reversals')->count(),
            'guest_refund_transactions' => DB::table('guest_refund_transactions')->count(),
            'cashier_sessions' => DB::table('cashier_sessions')->count(),
            'cash_count_evidence' => DB::table('cash_count_evidence')->count(),
            'cash_reconciliation_baselines' => DB::table('cash_reconciliation_baselines')->count(),
            'cash_reconciliations' => DB::table('cash_reconciliations')->count(),
        ];
    }

    private function spawnConcurrencyWorkers(FrontDeskStay $stay, CashierSession $session): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gc-a1-conc-' . Str::lower(Str::random(8));
        mkdir($dir, 0700, true);

        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'GeneralCashierCheckoutObligationConcurrencyWorker.php';
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';
        $processes = [];
        $runId = (string) Str::ulid();
        $created = [];

        for ($i = 0; $i < 2; $i++) {
            $argsFile = $dir . DIRECTORY_SEPARATOR . "args-w{$i}.json";
            $resultFile = $dir . DIRECTORY_SEPARATOR . "result-w{$i}.json";
            $stderrFile = $dir . DIRECTORY_SEPARATOR . "stderr-w{$i}.txt";
            array_push($created, $argsFile, $resultFile, $stderrFile);
            file_put_contents($argsFile, json_encode([
                'worker_id' => "w{$i}",
                'index' => $i,
                'run_id' => $runId,
                'result_file' => $resultFile,
                'barrier' => $barrier,
                'property_id' => $this->property->id,
                'company_id' => $this->company->id,
                'stay_id' => $stay->id,
                'actor_id' => $this->actor->id,
                'cashier_session_id' => $session->id,
            ]));

            $command = sprintf('%s %s %s', PHP_BINARY, escapeshellarg($worker), escapeshellarg($argsFile));
            $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
            $proc = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'ivorq_testing',
            ]));
            if (! is_resource($proc)) {
                throw new \RuntimeException('Unable to spawn GC-A1 concurrency worker.');
            }

            fclose($pipes[0]);
            $processes[$i] = [
                'proc' => $proc,
                'result_file' => $resultFile,
                'stderr_file' => $stderrFile,
            ];
        }

        $results = [];
        foreach ($processes as $i => $process) {
            $exitCode = proc_close($process['proc']);
            $decoded = file_exists($process['result_file'])
                ? json_decode(file_get_contents($process['result_file']), true)
                : ['error' => 'missing result file'];
            $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
            $decoded['_exit_code'] = $exitCode;
            if (file_exists($process['stderr_file'])) {
                $decoded['_stderr'] = trim(file_get_contents($process['stderr_file']));
            }
            $results[$i] = $decoded;
        }

        foreach ([
            $barrier . '-ready-w0.json',
            $barrier . '-ready-w1.json',
            $barrier . '-first-source-read-completed.json',
            $barrier . '-mutation-committed.json',
        ] as $file) {
            $created[] = $file;
        }

        foreach ($created as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);

        ksort($results);
        return array_values($results);
    }

    private function spawnProjectionWorkerWithoutMutator(FrontDeskStay $stay, CashierSession $session): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gc-a1-conc-fail-' . Str::lower(Str::random(8));
        mkdir($dir, 0700, true);

        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'GeneralCashierCheckoutObligationConcurrencyWorker.php';
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';
        $argsFile = $dir . DIRECTORY_SEPARATOR . 'args-w0.json';
        $resultFile = $dir . DIRECTORY_SEPARATOR . 'result-w0.json';
        $stderrFile = $dir . DIRECTORY_SEPARATOR . 'stderr-w0.txt';
        $runId = (string) Str::ulid();

        file_put_contents($argsFile, json_encode([
            'worker_id' => 'w0',
            'index' => 0,
            'run_id' => $runId,
            'result_file' => $resultFile,
            'barrier' => $barrier,
            'property_id' => $this->property->id,
            'company_id' => $this->company->id,
            'stay_id' => $stay->id,
            'actor_id' => $this->actor->id,
            'cashier_session_id' => $session->id,
        ]));

        $command = sprintf('%s %s %s', PHP_BINARY, escapeshellarg($worker), escapeshellarg($argsFile));
        $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
        $proc = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'ivorq_testing',
        ]));
        if (! is_resource($proc)) {
            throw new \RuntimeException('Unable to spawn GC-A1 failure worker.');
        }

        fclose($pipes[0]);
        $exitCode = proc_close($proc);
        $decoded = file_exists($resultFile)
            ? json_decode(file_get_contents($resultFile), true)
            : ['error' => 'missing result file'];
        $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
        $decoded['_exit_code'] = $exitCode;
        if (file_exists($stderrFile)) {
            $decoded['_stderr'] = trim(file_get_contents($stderrFile));
        }

        foreach ([
            $argsFile,
            $resultFile,
            $stderrFile,
            $barrier . '-ready-w0.json',
            $barrier . '-ready-w1.json',
            $barrier . '-first-source-read-completed.json',
            $barrier . '-mutation-committed.json',
        ] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);

        return $decoded;
    }
}

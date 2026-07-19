<?php

namespace Tests\Postgres\Operations\PMS;

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
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use RuntimeException;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GuestLedgerCheckoutTerminalFinancialAttestationSourceIntegrityTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutTerminalFinancialAttestationService $service;
    private PropertyBusinessDateOperationalLockService $lockService;
    private CashierSession $cashierSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);

        $this->cashierSession = new CashierSession();
        $this->cashierSession->forceFill([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
        ])->save();

        // Bind clear external ports for source integrity tests BEFORE resolving the service
        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'clear_posting'), 'source_identifiers' => []];
            }
        });
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'clear_hold'), 'source_identifiers' => []];
            }
        });
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'clear_conflict'), 'source_identifiers' => []];
            }
        });

        // Re-resolve service after port bindings
        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
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

    private function addFolioCharge(Folio $folio, string $amount): FolioItem
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

        return $item->fresh();
    }

    private function makeCashPayment(string $reservationId, string $guestId, string $amount, string $lifecycle = 'FULLY_ALLOCATED', ?Folio $folio = null): GuestPaymentTransaction
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->glfProperty->id,
            'payment_number' => 'GPM-' . uniqid(),
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'currency' => 'USD',
            'amount' => $amount,
            'cashier_session_id' => $this->cashierSession->id,
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
                'allocated_at' => now(),
                'allocated_by' => $this->glfActor->id,
                'source_snapshot' => json_encode([]),
                'created_at' => now(),
            ])->save();

            // Create matching negative FolioItem
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

    // ═══════════════════════════════════════════════════════════════════════
    // No folio — EVIDENCE_UNAVAILABLE
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_folio_returns_evidence_unavailable(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);

            $attestation = $this->service->attest($context, $stay->id);
            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialEvidenceUnavailable,
                $attestation->status
            );
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // One folio, zero balance, clear ports — READY
    // ═══════════════════════════════════════════════════════════════════════

    public function test_zero_balance_ready(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);

            $attestation = $this->service->attest($context, $stay->id);
            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady,
                $attestation->status
            );
            $this->assertEquals('0.00', $attestation->canonical_aggregate_balance);
            $this->assertEmpty($attestation->blocker_codes);
            $this->assertEmpty($attestation->review_reasons);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Individual non-zero folio balance — BLOCKED
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
            // Update cached totals to match (Folio denies mass assignment)
            DB::table('folios')->where('id', $folio->id)->update([
                'total_charges' => '150.00', 'balance' => '150.00',
            ]);

            $attestation = $this->service->attest($context, $stay->id);
            $this->assertEquals(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialBlocked,
                $attestation->status
            );
            $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO', $attestation->blocker_codes);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Multiple folios
    // ═══════════════════════════════════════════════════════════════════════

    public function test_multiple_folios_evaluated(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id, ['window_number' => 2]);

            $attestation = $this->service->attest($context, $stay->id);
            $this->assertEquals(2, $attestation->folio_count);
            $this->assertCount(2, $attestation->folio_ids);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Closed folio — REVIEW_REQUIRED
    // ═══════════════════════════════════════════════════════════════════════

    public function test_closed_folio_review_required(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $this->makeFolio($reservation->id, $guest->id, ['status' => 'closed']);

            $attestation = $this->service->attest($context, $stay->id);
            $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED', $attestation->review_reasons);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Unresolved payment — BLOCKED
    // ═══════════════════════════════════════════════════════════════════════

    public function test_unresolved_payment_blocked(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $guest = $reservation->primaryGuest;
            $stay = $this->makeStay($reservation->id, $guest->id);
            $folio = $this->makeFolio($reservation->id, $guest->id);
            $this->addFolioCharge($folio, '100.00');
            // Recorded but not allocated
            $this->makeCashPayment($reservation->id, $guest->id, '100.00', 'RECORDED');

            $attestation = $this->service->attest($context, $stay->id);
            $this->assertContains('GUEST_PAYMENT_UNRESOLVED', $attestation->blocker_codes);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GLF-D snapshot equivalence after evaluator extraction
    // ═══════════════════════════════════════════════════════════════════════

    public function test_glf_d_equivalence_preserved(): void
    {
        // Verify GLF-D projection service still works via the shared evaluator
        $projectionService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->glfProperty->id);

        $perm = \Modules\Foundation\Authorization\Models\Permission::firstOrCreate([
            'name' => \Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,
            'guard_name' => 'web',
        ]);
        $this->glfActor->givePermissionTo($perm);

        auth()->login($this->glfActor);
        $this->actingAs($this->glfActor);

        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation->id, $guest->id);
        $this->makeFolio($reservation->id, $guest->id);

        $result = $projectionService->project($this->glfActor, $stay->id);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result->source_fingerprint);
        $this->assertEquals($this->glfProperty->id, $result->property_id);
    }
}

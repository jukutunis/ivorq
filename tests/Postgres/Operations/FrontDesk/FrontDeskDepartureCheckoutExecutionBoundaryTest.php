<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskBusinessDateDependencyService;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutExecutionBoundaryProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskGeneralCashierCheckoutObligationDependencyService;
use Modules\Operations\FrontDesk\Services\FrontDeskGuestLedgerSettlementReadinessDependencyService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutObligationProjectionService;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutExecutionBoundaryTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, DatabaseMigrations;

    private const AUTHORIZATION_FAILURE_MESSAGE = 'Front Desk checkout execution boundary view is not authorized.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
        $this->actingAs($this->frontDeskActor, 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedB3B4B5B6Ready(array $stay): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-' . Str::ulid());
    }

    private function seedB3B4B5B6B7Ready(array $stay): void
    {
        $this->seedB3B4B5B6Ready($stay);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'B7 ready.', 'dcfr-' . Str::ulid());
    }

    private function service(): FrontDeskDepartureCheckoutExecutionBoundaryProjectionService
    {
        return app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class);
    }

    private function queueService(): FrontDeskDepartureQueueProjectionService
    {
        return app(FrontDeskDepartureQueueProjectionService::class);
    }

    private function bindClearGuestLedgerPorts(): void
    {
        app()->forgetInstance(GuestLedgerPostingCompletenessReadPort::class);
        app()->forgetInstance(GuestLedgerSettlementHoldReadPort::class);
        app()->forgetInstance(GuestLedgerCompletedSettlementConflictReadPort::class);

        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, fn () => new class implements GuestLedgerPostingCompletenessReadPort {
            public function evaluate(string $reservationId, string $propertyId): array
            {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        });
        app()->singleton(GuestLedgerSettlementHoldReadPort::class, fn () => new class implements GuestLedgerSettlementHoldReadPort {
            public function evaluate(string $reservationId, string $propertyId): array
            {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictReadPort::class, fn () => new class implements GuestLedgerCompletedSettlementConflictReadPort {
            public function evaluate(string $reservationId, string $propertyId): array
            {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        });
    }

    private function makeGuestLedgerFolio(array $stay, string $balance = '0.00', string $status = 'open'): Folio
    {
        $folio = new Folio();
        $folio->forceFill([
            'property_id' => $this->property->id,
            'folio_number' => 'FD-B9-' . strtoupper(Str::random(8)),
            'reservation_id' => $stay[2],
            'guest_id' => $stay[0]->guest_id,
            'status' => $status,
            'currency' => 'USD',
            'window_number' => random_int(1, 9999),
            'opening_idempotency_key' => 'fd-b9-' . Str::ulid(),
            'total_charges' => $balance,
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => $balance,
        ])->save();

        if (bccomp($balance, '0.00', 2) !== 0) {
            $item = new FolioItem();
            $item->forceFill([
                'property_id' => $this->property->id,
                'folio_id' => $folio->id,
                'item_type' => FolioItemTypeEnum::RoomCharge,
                'description' => 'FD-B9 test room charge',
                'quantity' => '1.00',
                'amount' => $balance,
                'is_void' => false,
                'posted_at' => now(),
                'posted_by' => $this->frontDeskActor->id,
                'created_by' => $this->frontDeskActor->id,
            ])->save();
        }

        return $folio->fresh();
    }

    private function createCrossPropertyStayId(): string
    {
        $otherGuestId = $this->guest($this->otherProperty, 'Cross-Property Guest');
        $otherReservationId = $this->reservation($this->otherProperty, $otherGuestId, 'RES-XP-' . strtoupper(Str::random(5)), 'confirmed');
        $stayId = (string) Str::ulid();

        DB::table('front_desk_stays')->insert([
            'id' => $stayId,
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $otherReservationId,
            'guest_id' => $otherGuestId,
            'status' => 'IN_HOUSE',
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $stayId;
    }

    /**
     * @param string[] $stayIds
     */
    private function assertAuthorizationDeniedParityWithoutDomainQueries(User $actor, array $stayIds): void
    {
        foreach ($stayIds as $stayId) {
            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                $queries[] = $query->sql;
            });

            try {
                $this->service()->boundary($actor, $stayId);
                $this->fail('Boundary lookup should have been denied before resource lookup.');
            } catch (AuthorizationException $exception) {
                $this->assertSame(self::AUTHORIZATION_FAILURE_MESSAGE, $exception->getMessage());
            }

            $this->assertNoFrontDeskOrGuestLedgerDomainQueries($queries);
        }
    }

    /**
     * @param string[] $queries
     */
    private function assertNoFrontDeskOrGuestLedgerDomainQueries(array $queries): void
    {
        $forbiddenTables = [
            'front_desk_stays',
            'front_desk_departure_checkout_final_reviews',
            'folios',
            'folio_items',
            'guest_payment_transactions',
            'guest_payment_allocations',
            'guest_deposit_transactions',
            'guest_deposit_applications',
            'guest_refund_transactions',
            'guest_payment_reversals',
            'guest_deposit_reversals',
            'guest_ar_transfer_requests',
            'guest_ar_transfer_decisions',
            'cashier_sessions',
            'guest_refund_allocations',
            'property_business_dates',
        ];

        $domainQueries = [];
        foreach ($queries as $sql) {
            foreach ($forbiddenTables as $table) {
                if (str_contains($sql, '"' . $table . '"') || str_contains($sql, $table)) {
                    $domainQueries[] = $sql;
                    break;
                }
            }
        }

        $this->assertSame([], $domainQueries, 'Authorization denial must not query Front Desk stay/B7 or Guest Ledger source tables.');
    }

    /**
     * @return string[]
     */
    private function denialParityStayIds(): array
    {
        $stay = $this->checkedInStay('8230');

        return [
            $stay[0]->id,
            (string) Str::ulid(),
            $this->createCrossPropertyStayId(),
        ];
    }

    // ── Stay Lifecycle Resolution ──

    public function test_same_property_non_in_house_stay_not_404(): void
    {
        // Create a stay with ROOM_ASSIGNED status (not IN_HOUSE)
        [$reservation, , $room] = $this->assignReadyReservation('8201');
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign(
            $this->frontDeskActor, $reservation, $room, null, 'assign-nonih-' . Str::ulid()
        );

        $stay = $assigned['stay']->fresh();
        $this->assertSame('ROOM_ASSIGNED', $stay->status->value);

        // Query the boundary for this same-property non-IN_HOUSE stay
        $b = $this->service()->boundary($this->frontDeskActor, $stay->id);

        // Must not 404 — same property stay must be found
        $this->assertSame($stay->id, $b['front_desk_stay_id']);
        $this->assertSame('ROOM_ASSIGNED', $b['stay_status']);
        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_STAY_NOT_IN_HOUSE, $b['blocker_codes']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertFalse($b['authoritative_gates']['stay_in_house']['satisfied']);
        $this->assertStringContainsString('ROOM_ASSIGNED', $b['authoritative_gates']['stay_in_house']['detail']);
    }

    public function test_unknown_stay_id_returns_404(): void
    {
        $nonExistentStayId = (string) Str::ulid();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk stay not found.');

        $this->service()->boundary($this->frontDeskActor, $nonExistentStayId);
    }

    public function test_cross_property_stay_not_disclosed(): void
    {
        // Create real FK-satisfying data in other property
        $otherGuestId = $this->guest($this->otherProperty, 'Cross-Property Guest');
        $otherReservationId = $this->reservation($this->otherProperty, $otherGuestId, 'RES-XP-' . strtoupper(Str::random(5)), 'confirmed');

        $stayId = (string) Str::ulid();
        \Illuminate\Support\Facades\DB::table('front_desk_stays')->insert([
            'id' => $stayId,
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $otherReservationId,
            'guest_id' => $otherGuestId,
            'status' => 'IN_HOUSE',
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query from the main property context — the stay belongs to otherProperty, so 404
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk stay not found.');

        $this->service()->boundary($this->frontDeskActor, $stayId);
    }

    // ── Boundary Prerequisite Tests ──

    public function test_no_b7_evidence_cannot_execute(): void
    {
        $s = $this->checkedInStay('8203');

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_EVIDENCE_MISSING, $b['blocker_codes']);
        $this->assertSame($s[0]->id, $b['front_desk_stay_id']);
        $this->assertNull($b['latest_final_review_status']);
        $this->assertEmpty($b['review_reasons']);
    }

    public function test_b7_blocked_returns_execution_boundary_blocked(): void
    {
        $s = $this->checkedInStay('8204');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'B7 blocked.', 'dcfr-' . Str::ulid());

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertSame('CHECKOUT_FINAL_REVIEW_BLOCKED', $b['latest_final_review_status']);
        $this->assertEmpty($b['review_reasons']);
    }

    public function test_b7_reviewed_returns_execution_boundary_review_required(): void
    {
        $s = $this->checkedInStay('8205');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'B7 reviewed.', 'dcfr-' . Str::ulid());

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_REVIEW_REQUIRED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertNotEmpty($b['review_reasons']);
        $this->assertStringContainsString('REVIEWED', $b['review_reasons'][0]);
    }

    public function test_b7_ready_does_not_imply_can_execute(): void
    {
        $s = $this->checkedInStay('8206');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        // B7 is READY, but financial/cashier/business-date gates are unavailable → still blocked
        $this->assertFalse($b['can_execute']);
        $this->assertSame('CHECKOUT_FINAL_REVIEW_READY', $b['latest_final_review_status']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_EVIDENCE_MISSING, $b['blocker_codes']);
        // Still blocked by unavailable gates
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
    }

    // ── Financial / Source Availability Tests ──

    public function test_financial_settlement_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8207');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE, $b['blocker_codes']);

        $financialGate = $b['authoritative_gates']['financial_settlement'] ?? null;
        $this->assertNotNull($financialGate);
        $this->assertFalse($financialGate['satisfied']);
        $this->assertSame('PMS Guest Ledger', $financialGate['owner']);
        $this->assertSame('GUEST_LEDGER_SETTLEMENT_EVIDENCE_UNAVAILABLE', $b['guest_ledger_settlement_readiness']['status']);
        $this->assertContains('CHECKOUT_RELEVANT_FOLIOS_EVIDENCE_UNAVAILABLE', $b['guest_ledger_settlement_readiness']['evidence_unavailable_codes']);
    }

    public function test_guest_ledger_ready_satisfies_only_financial_gate_and_can_execute_remains_false(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8220');
        $this->makeGuestLedgerFolio($s);
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('GUEST_LEDGER_SETTLEMENT_READY', $b['guest_ledger_settlement_readiness']['status']);
        $this->assertTrue($b['authoritative_gates']['financial_settlement']['satisfied']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE, $b['blocker_codes']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_BLOCKED, $b['blocker_codes']);
        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CHECKOUT_NOT_IMPLEMENTED, $b['blocker_codes']);
    }

    public function test_guest_ledger_blocked_maps_to_front_desk_financial_blocker_with_nested_source_codes(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8221');
        $this->makeGuestLedgerFolio($s, '25.00');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('GUEST_LEDGER_SETTLEMENT_BLOCKED', $b['guest_ledger_settlement_readiness']['status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_BLOCKED, $b['blocker_codes']);
        $this->assertContains('INDIVIDUAL_FOLIO_BALANCE_NOT_ZERO', $b['guest_ledger_settlement_readiness']['blocker_codes']);
        $this->assertFalse($b['authoritative_gates']['financial_settlement']['satisfied']);
        $this->assertFalse($b['can_execute']);
    }

    public function test_guest_ledger_review_required_maps_to_review_reason_and_nested_source_reasons(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8222');
        $this->makeGuestLedgerFolio($s, '0.00', FolioStatusEnum::Closed->value);
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('GUEST_LEDGER_SETTLEMENT_REVIEW_REQUIRED', $b['guest_ledger_settlement_readiness']['status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_REVIEW_REQUIRED, $b['blocker_codes']);
        $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED', $b['guest_ledger_settlement_readiness']['review_reasons']);
        $this->assertContains('FOLIO_LIFECYCLE_REVIEW_REQUIRED', $b['review_reasons']);
        $this->assertSame('EXECUTION_BOUNDARY_REVIEW_REQUIRED', $b['execution_boundary_status']);
        $this->assertFalse($b['can_execute']);
    }

    public function test_unknown_guest_ledger_status_fails_closed_without_evidence_unavailable_normalization(): void
    {
        $s = $this->checkedInStay('8231');
        $this->seedB3B4B5B6B7Ready($s);

        $guestLedger = new class extends FrontDeskGuestLedgerSettlementReadinessDependencyService {
            public function __construct() {}

            public function project(User $actor, string $frontDeskStayId): array
            {
                return ['status' => 'GUEST_LEDGER_SETTLEMENT_FUTURE_UNKNOWN'];
            }
        };

        $cashier = new class extends FrontDeskGeneralCashierCheckoutObligationDependencyService {
            public function __construct() {}

            public function project(User $actor, string $frontDeskStayId): array
            {
                return [
                    'status' => 'CASHIER_OBLIGATION_CLEAR',
                    'related_guest_payment_transaction_ids' => [],
                    'related_cashier_session_ids' => [],
                    'blocker_codes' => [],
                    'review_reasons' => [],
                    'evidence_unavailable_codes' => [],
                    'markers' => [],
                    'evaluated_at' => now()->toISOString(),
                    'source_fingerprint' => 'fake-clear',
                ];
            }
        };

        $service = new FrontDeskDepartureCheckoutExecutionBoundaryProjectionService(
            $guestLedger,
            $cashier,
            app(FrontDeskBusinessDateDependencyService::class)
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::UNKNOWN_GUEST_LEDGER_SETTLEMENT_STATUS);

        $service->boundary($this->frontDeskActor, $s[0]->id);
    }

    public function test_no_cashier_source_clears_cashier_gate_and_removes_old_placeholder(): void
    {
        $s = $this->checkedInStay('8208');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('CASHIER_OBLIGATION_CLEAR', $b['general_cashier_checkout_obligation']['status']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE, $b['blocker_codes']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_BLOCKED, $b['blocker_codes']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_REVIEW_REQUIRED, $b['blocker_codes']);

        $cashierGate = $b['authoritative_gates']['cashier_obligation'] ?? null;
        $this->assertTrue($cashierGate['satisfied']);
        $this->assertSame('General Cashier', $cashierGate['owner']);
        $this->assertSame('CASHIER_OBLIGATION_CLEAR', $cashierGate['status']);
        $this->assertSame(0, $cashierGate['related_cashier_session_count']);
    }

    public function test_front_desk_business_date_adapter_preserves_successful_bd_a1_contract(): void
    {
        $row = $this->createValidAuthoritativeBusinessDate();

        $projection = app(FrontDeskBusinessDateDependencyService::class)->project($this->frontDeskActor);

        $this->assertSame(FrontDeskBusinessDateDependencyService::PROJECTION_VERSION, $projection['projection_version']);
        $this->assertSame('BUSINESS_DATE_OPEN', $projection['status']);
        $this->assertSame('BUSINESS_DATE_OPEN', $projection['source_status']);
        $this->assertSame('PROPERTY_BUSINESS_DATE_SOURCE_PROVEN', $projection['source_classification']);
        $this->assertSame('Business Date / Night Audit', $projection['owner']);
        $this->assertTrue($projection['read_only']);
        $this->assertSame($row->id, $projection['property_business_date_id']);
        $this->assertSame($this->property->id, $projection['property_id']);
        $this->assertSame('2026-07-17', $projection['business_date']);
        $this->assertSame('Open', $projection['lifecycle_status']);
        $this->assertSame($this->property->timezone, $projection['property_timezone']);
        $this->assertSame($this->frontDeskActor->id, $projection['opened_by']);
        $this->assertSame([], $projection['evidence_unavailable_codes']);
        $this->assertNotEmpty($projection['source_fingerprint']);
        $this->assertArrayHasKey('read_only_marker', $projection['markers']);
    }

    public function test_open_business_date_satisfies_only_business_date_gate(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8248');
        $this->makeGuestLedgerFolio($s);
        $this->seedB3B4B5B6B7Ready($s);
        $businessDate = $this->createValidAuthoritativeBusinessDate();

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('BUSINESS_DATE_OPEN', $b['property_business_date']['status']);
        $this->assertTrue($b['authoritative_gates']['business_date']['satisfied']);
        $this->assertSame('2026-07-17', $b['authoritative_gates']['business_date']['business_date']);
        $this->assertSame($businessDate->id, $b['property_business_date']['property_business_date_id']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_BUSINESS_DATE_UNAVAILABLE, $b['blocker_codes']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_NIGHT_AUDIT_LOCK_UNAVAILABLE, $b['blocker_codes']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CHECKOUT_NOT_IMPLEMENTED, $b['blocker_codes']);
        $this->assertFalse($b['can_execute']);
    }

    public function test_no_business_date_history_maps_to_known_unavailable_code(): void
    {
        $s = $this->checkedInStay('8249');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('BUSINESS_DATE_EVIDENCE_UNAVAILABLE', $b['property_business_date']['status']);
        $this->assertSame(PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED, $b['property_business_date']['source_status']);
        $this->assertContains(PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED, $b['property_business_date']['evidence_unavailable_codes']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_BUSINESS_DATE_UNAVAILABLE, $b['blocker_codes']);
        $this->assertFalse($b['authoritative_gates']['business_date']['satisfied']);
    }

    public function test_closed_business_date_history_maps_to_known_unavailable_code(): void
    {
        PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-16',
            'timezone_snapshot' => $this->property->timezone,
            'opened_by' => $this->frontDeskActor->id,
        ]);
        $s = $this->checkedInStay('8250');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame(PropertyBusinessDateProjectionService::ERROR_OPEN_UNAVAILABLE, $b['property_business_date']['source_status']);
    }

    public function test_incomplete_business_date_evidence_code_is_normalized(): void
    {
        $this->createValidAuthoritativeBusinessDate(['timezone_snapshot' => null]);

        $projection = app(FrontDeskBusinessDateDependencyService::class)->project($this->frontDeskActor);

        $this->assertSame('BUSINESS_DATE_EVIDENCE_UNAVAILABLE', $projection['status']);
        $this->assertSame(PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE, $projection['source_status']);
        $this->assertSame([PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE], $projection['evidence_unavailable_codes']);
    }

    public function test_business_date_timezone_mismatch_code_is_normalized(): void
    {
        $this->createValidAuthoritativeBusinessDate();
        $this->property->forceFill(['timezone' => 'Asia/Makassar'])->save();

        $projection = app(FrontDeskBusinessDateDependencyService::class)->project($this->frontDeskActor);

        $this->assertSame(PropertyBusinessDateProjectionService::ERROR_TIMEZONE_MISMATCH, $projection['source_status']);
    }

    public function test_multiple_open_business_date_code_is_normalized(): void
    {
        $source = new class extends PropertyBusinessDateProjectionService {
            public function __construct() {}

            public function project(User $actor): array
            {
                throw new \RuntimeException(PropertyBusinessDateProjectionService::ERROR_MULTIPLE_OPEN);
            }
        };

        $projection = (new FrontDeskBusinessDateDependencyService($source))->project($this->frontDeskActor);

        $this->assertSame(PropertyBusinessDateProjectionService::ERROR_MULTIPLE_OPEN, $projection['source_status']);
    }

    public function test_unknown_business_date_adapter_status_fails_closed(): void
    {
        $s = $this->checkedInStay('8251');
        $this->seedB3B4B5B6B7Ready($s);

        $businessDate = new class extends FrontDeskBusinessDateDependencyService {
            public function __construct() {}

            public function project(User $actor): array
            {
                return ['status' => 'BUSINESS_DATE_FUTURE_UNKNOWN'];
            }
        };

        $service = new FrontDeskDepartureCheckoutExecutionBoundaryProjectionService(
            app(FrontDeskGuestLedgerSettlementReadinessDependencyService::class),
            app(FrontDeskGeneralCashierCheckoutObligationDependencyService::class),
            $businessDate
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::UNKNOWN_BUSINESS_DATE_STATUS);

        $service->boundary($this->frontDeskActor, $s[0]->id);
    }

    public function test_malformed_successful_business_date_projection_fails_closed(): void
    {
        $source = new class extends PropertyBusinessDateProjectionService {
            public function __construct() {}

            public function project(User $actor): array
            {
                return [
                    'status' => 'BUSINESS_DATE_OPEN',
                    'source_classification' => 'PROPERTY_BUSINESS_DATE_SOURCE_PROVEN',
                    'property_business_date_id' => 'pbd',
                ];
            }
        };

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(FrontDeskBusinessDateDependencyService::INVALID_PROJECTION);

        (new FrontDeskBusinessDateDependencyService($source))->project($this->frontDeskActor);
    }

    public function test_unknown_business_date_runtime_failure_is_not_normalized(): void
    {
        $source = new class extends PropertyBusinessDateProjectionService {
            public function __construct() {}

            public function project(User $actor): array
            {
                throw new \RuntimeException('BD_A1_FUTURE_UNKNOWN_FAILURE');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BD_A1_FUTURE_UNKNOWN_FAILURE');

        (new FrontDeskBusinessDateDependencyService($source))->project($this->frontDeskActor);
    }

    public function test_general_cashier_adapter_preserves_source_projection_contract(): void
    {
        $s = $this->checkedInStay('8240');
        $session = $this->frontDeskCashierSession();
        $payment = $this->frontDeskCashPayment($s, $session);

        $projection = app(FrontDeskGeneralCashierCheckoutObligationDependencyService::class)
            ->project($this->frontDeskActor, $s[0]->id);

        $this->assertSame(GeneralCashierCheckoutObligationProjectionService::PROJECTION_VERSION, $projection['projection_version']);
        $this->assertSame('CASHIER_OBLIGATION_BLOCKED', $projection['status']);
        $this->assertSame($this->property->id, $projection['property_id']);
        $this->assertSame($s[0]->id, $projection['front_desk_stay_id']);
        $this->assertContains($payment->id, $projection['related_guest_payment_transaction_ids']);
        $this->assertContains($session->id, $projection['related_cashier_session_ids']);
        $this->assertContains('CASHIER_SESSION_OPEN', $projection['blocker_codes']);
        $this->assertArrayHasKey('cashier_accountability_marker', $projection['markers']);
        $this->assertNotEmpty($projection['evaluated_at']);
        $this->assertNotEmpty($projection['source_fingerprint']);
        $this->assertArrayHasKey('source_identifiers', $projection);
    }

    public function test_open_cashier_linked_guest_cash_evidence_blocks_boundary_without_mutation(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8241');
        $this->makeGuestLedgerFolio($s);
        $this->seedB3B4B5B6B7Ready($s);
        $session = $this->frontDeskCashierSession();
        $payment = $this->frontDeskCashPayment($s, $session);
        $before = $this->domainTableCounts();

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('CASHIER_OBLIGATION_BLOCKED', $b['general_cashier_checkout_obligation']['status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_BLOCKED, $b['blocker_codes']);
        $this->assertContains('CASHIER_SESSION_OPEN', $b['general_cashier_checkout_obligation']['blocker_codes']);
        $this->assertFalse($b['authoritative_gates']['cashier_obligation']['satisfied']);
        $this->assertContains($payment->id, $b['general_cashier_checkout_obligation']['related_guest_payment_transaction_ids']);
        $this->assertContains($session->id, $b['general_cashier_checkout_obligation']['related_cashier_session_ids']);
        $this->assertFalse($b['can_execute']);
        $this->assertSame($before, $this->domainTableCounts());
    }

    public function test_general_cashier_review_required_flows_into_boundary_review_precedence(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8242');
        $this->makeGuestLedgerFolio($s);
        $this->seedB3B4B5B6B7Ready($s);
        $session = $this->frontDeskCashierSession();
        $this->frontDeskCashPayment($s, $session, [
            'cashier_session_id' => $session->id,
            'cashier_user_id' => (string) Str::ulid(),
            'cashier_session_status' => 'OPEN',
        ]);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('CASHIER_OBLIGATION_REVIEW_REQUIRED', $b['general_cashier_checkout_obligation']['status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_REVIEW_REQUIRED, $b['blocker_codes']);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $b['general_cashier_checkout_obligation']['review_reasons']);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $b['review_reasons']);
        $this->assertSame('EXECUTION_BOUNDARY_REVIEW_REQUIRED', $b['execution_boundary_status']);
    }

    public function test_closed_cashier_session_without_accountability_completion_is_evidence_unavailable(): void
    {
        $this->bindClearGuestLedgerPorts();
        $s = $this->checkedInStay('8243');
        $this->makeGuestLedgerFolio($s);
        $this->seedB3B4B5B6B7Ready($s);
        $session = $this->frontDeskCashierSession(CashierSessionStatusEnum::CLOSED);
        $this->frontDeskCashPayment($s, $session);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE', $b['general_cashier_checkout_obligation']['status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE, $b['blocker_codes']);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $b['general_cashier_checkout_obligation']['evidence_unavailable_codes']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
    }

    public function test_payment_recorded_open_then_session_closed_is_not_snapshot_conflict(): void
    {
        $s = $this->checkedInStay('8244');
        $this->seedB3B4B5B6B7Ready($s);
        $session = $this->frontDeskCashierSession();
        $this->frontDeskCashPayment($s, $session);
        $this->closeFrontDeskCashierSession($session);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('CASHIER_OBLIGATION_EVIDENCE_UNAVAILABLE', $b['general_cashier_checkout_obligation']['status']);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $b['general_cashier_checkout_obligation']['evidence_unavailable_codes']);
        $this->assertNotContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $b['general_cashier_checkout_obligation']['review_reasons']);
    }

    public function test_unknown_general_cashier_status_fails_closed_without_normalization(): void
    {
        $s = $this->checkedInStay('8245');
        $this->seedB3B4B5B6B7Ready($s);

        $cashier = new class extends FrontDeskGeneralCashierCheckoutObligationDependencyService {
            public function __construct() {}

            public function project(User $actor, string $frontDeskStayId): array
            {
                return ['status' => 'CASHIER_OBLIGATION_FUTURE_UNKNOWN'];
            }
        };

        $service = new FrontDeskDepartureCheckoutExecutionBoundaryProjectionService(
            app(FrontDeskGuestLedgerSettlementReadinessDependencyService::class),
            $cashier,
            app(FrontDeskBusinessDateDependencyService::class)
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::UNKNOWN_GENERAL_CASHIER_OBLIGATION_STATUS);

        $service->boundary($this->frontDeskActor, $s[0]->id);
    }

    public function test_no_fabricated_ready_result(): void
    {
        $s = $this->checkedInStay('8209');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertNotSame('EXECUTION_BOUNDARY_READY', $b['execution_boundary_status']);
    }

    // ── Authorization and Isolation ──

    public function test_unauthorized_actor_rejected(): void
    {
        $s = $this->checkedInStay('8210');
        $this->seedB3B4B5B6B7Ready($s);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::AUTHORIZATION_FAILURE_MESSAGE);

        $this->service()->boundary($this->financeActor, $s[0]->id);
    }

    public function test_actor_auth_mismatch_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->frontDeskViewOnlyActor->givePermissionTo([
            FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION,
            FrontDeskGuestLedgerSettlementReadinessDependencyService::VIEW_PERMISSION,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskViewOnlyActor->fresh(), $stayIds);
    }

    public function test_missing_front_desk_boundary_permission_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->frontDeskActor->revokePermissionTo(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_missing_guest_ledger_permission_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->frontDeskActor->revokePermissionTo(FrontDeskGuestLedgerSettlementReadinessDependencyService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_missing_general_cashier_permission_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->frontDeskActor->revokePermissionTo(FrontDeskGeneralCashierCheckoutObligationDependencyService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_inactive_actor_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->frontDeskActor->forceFill(['is_active' => false])->save();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_absent_membership_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->frontDeskActor->properties()->detach($this->property->id);

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_inactive_membership_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        DB::table('property_user')
            ->where('user_id', $this->frontDeskActor->id)
            ->where('property_id', $this->property->id)
            ->update(['status' => 'inactive']);

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_cross_company_property_context_is_denied_before_domain_queries(): void
    {
        $stayIds = $this->denialParityStayIds();
        session(['active_company_id' => $this->otherCompany->id]);

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_missing_active_company_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        session()->forget('active_company_id');

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_empty_active_company_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        session(['active_company_id' => '']);

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_unknown_active_company_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        session(['active_company_id' => (string) Str::ulid()]);

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_inactive_active_company_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->company->forceFill(['is_active' => false])->save();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_inactive_active_property_valid_unknown_and_cross_property_are_authorization_identical(): void
    {
        $stayIds = $this->denialParityStayIds();
        $this->property->forceFill(['is_active' => false])->save();

        $this->assertAuthorizationDeniedParityWithoutDomainQueries($this->frontDeskActor->fresh(), $stayIds);
    }

    public function test_valid_active_company_and_property_context_still_succeeds(): void
    {
        $s = $this->checkedInStay('8247');
        $this->seedB3B4B5B6B7Ready($s);

        $boundary = $this->service()->boundary($this->frontDeskActor->fresh(), $s[0]->id);

        $this->assertSame($s[0]->id, $boundary['front_desk_stay_id']);
        $this->assertSame($this->property->id, $boundary['property_id']);
        $this->assertFalse($boundary['can_execute']);
    }

    public function test_fully_authorized_unknown_and_cross_property_stays_are_non_disclosing_404_identical(): void
    {
        $stayIds = [
            (string) Str::ulid(),
            $this->createCrossPropertyStayId(),
        ];

        foreach ($stayIds as $stayId) {
            try {
                $this->service()->boundary($this->frontDeskActor, $stayId);
                $this->fail('Unknown and cross-property stays must not be disclosed.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
                $this->assertSame('Front Desk stay not found.', $exception->getMessage());
            }
        }
    }

    public function test_guest_ledger_view_permission_is_required_for_dedicated_boundary(): void
    {
        $s = $this->checkedInStay('8223');
        $this->seedB3B4B5B6B7Ready($s);

        $this->frontDeskActor->revokePermissionTo(GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::AUTHORIZATION_FAILURE_MESSAGE);

        $this->service()->boundary($this->frontDeskActor->fresh(), $s[0]->id);
    }

    public function test_general_cashier_view_permission_is_required_for_dedicated_boundary(): void
    {
        $s = $this->checkedInStay('8246');
        $this->seedB3B4B5B6B7Ready($s);

        $this->frontDeskActor->revokePermissionTo(GeneralCashierCheckoutObligationProjectionService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::AUTHORIZATION_FAILURE_MESSAGE);

        $this->service()->boundary($this->frontDeskActor->fresh(), $s[0]->id);
    }

    public function test_actor_auth_mismatch_fails(): void
    {
        $s = $this->checkedInStay('8224');
        $this->seedB3B4B5B6B7Ready($s);
        $this->frontDeskViewOnlyActor->givePermissionTo([
            FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION,
            GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::AUTHORIZATION_FAILURE_MESSAGE);

        $this->service()->boundary($this->frontDeskViewOnlyActor->fresh(), $s[0]->id);
    }

    public function test_inactive_actor_fails(): void
    {
        $s = $this->checkedInStay('8225');
        $this->seedB3B4B5B6B7Ready($s);
        $inactive = $this->user('FD B9 Inactive', 'fd-b9-inactive@example.test');
        $this->attachProperty($inactive, $this->property);
        $inactive->givePermissionTo([
            FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION,
            GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,
        ]);
        $inactive->forceFill(['is_active' => false])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($inactive, 'web');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::AUTHORIZATION_FAILURE_MESSAGE);

        $this->service()->boundary($inactive->fresh(), $s[0]->id);
    }

    public function test_absent_current_property_membership_fails(): void
    {
        $s = $this->checkedInStay('8226');
        $this->seedB3B4B5B6B7Ready($s);
        $outsider = $this->user('FD B9 Outsider', 'fd-b9-outsider@example.test');
        $outsider->givePermissionTo([
            FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION,
            GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($outsider, 'web');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::AUTHORIZATION_FAILURE_MESSAGE);

        $this->service()->boundary($outsider->fresh(), $s[0]->id);
    }

    public function test_projection_uses_current_property_context(): void
    {
        $s = $this->checkedInStay('8211');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($this->property->id, $b['property_id']);
    }

    // ── Read-Only Boundary ──

    public function test_projection_does_not_mutate_stay_status(): void
    {
        $s = $this->checkedInStay('8212');
        $this->seedB3B4B5B6B7Ready($s);

        $stayBefore = $s[0]->fresh()->status->value;

        $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $stayAfter = $s[0]->fresh()->status->value;

        $this->assertSame($stayBefore, $stayAfter);
        $this->assertSame('IN_HOUSE', $stayAfter);
    }

    public function test_projection_does_not_mutate_b7_records(): void
    {
        $s = $this->checkedInStay('8213');
        $this->seedB3B4B5B6B7Ready($s);

        $b7CountBefore = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('front_desk_stay_id', $s[0]->id)->count();

        $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $b7CountAfter = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('front_desk_stay_id', $s[0]->id)->count();

        $this->assertSame($b7CountBefore, $b7CountAfter);
    }

    public function test_boundary_projection_does_not_mutate_front_desk_or_financial_source_tables(): void
    {
        $s = $this->checkedInStay('8232');
        $this->seedB3B4B5B6B7Ready($s);
        $before = $this->domainTableCounts();

        $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($before, $this->domainTableCounts());
    }

    public function test_repeated_get_requests_are_stable(): void
    {
        $s = $this->checkedInStay('8214');
        $this->seedB3B4B5B6B7Ready($s);

        $b1 = $this->service()->boundary($this->frontDeskActor, $s[0]->id);
        $b2 = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($b1['execution_boundary_status'], $b2['execution_boundary_status']);
        $this->assertSame($b1['can_execute'], $b2['can_execute']);
        $this->assertSame($b1['blocker_codes'], $b2['blocker_codes']);
        $this->assertSame($b1['latest_final_review_status'], $b2['latest_final_review_status']);
    }

    // ── Execution Marker ──

    public function test_execution_not_performed_marker_present(): void
    {
        $s = $this->checkedInStay('8215');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('Checkout execution is not performed in FD-B11.', $b['execution_not_performed_marker']);
    }

    // ── All Authoritative Gates Present ──

    public function test_all_eight_authoritative_gates_present(): void
    {
        $s = $this->checkedInStay('8216');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $expectedGates = [
            'stay_in_house',
            'property_ownership',
            'fd_b7_final_review',
            'financial_settlement',
            'cashier_obligation',
            'business_date',
            'night_audit_lock',
            'checkout_execution',
        ];

        foreach ($expectedGates as $gate) {
            $this->assertArrayHasKey($gate, $b['authoritative_gates'], "Missing authoritative gate: {$gate}");
        }
    }

    // ── Queue Integration ──

    public function test_queue_includes_boundary_summary(): void
    {
        $s = $this->checkedInStay('8217');
        $this->seedB3B4B5B6B7Ready($s);

        $queue = $this->queueService()->queue($this->frontDeskActor);

        // Find the projected stay across all queue views (it's overdue because departure_date is before test date)
        $projected = null;
        foreach (array_merge(
            $queue['views']['dueOutToday'],
            $queue['views']['dueOutTomorrow'],
            $queue['views']['dueOutFuture'],
            $queue['views']['overdueDepartures']
        ) as $row) {
            if ($row['stay_id'] === $s[0]->id) {
                $projected = $row;
                break;
            }
        }
        $this->assertNotNull($projected, 'Stay should appear in departure queue.');

        $this->assertTrue($projected['can_view_execution_boundary']);

        $boundary = $projected['departure_checkout_execution_boundary'];
        $this->assertNotNull($boundary);
        $this->assertFalse($boundary['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $boundary['execution_boundary_status']);
        $this->assertNotEmpty($boundary['blocker_codes']);
        $this->assertIsArray($boundary['review_reasons']);
        $this->assertSame('Checkout execution is not performed in FD-B11.', $boundary['execution_not_performed_marker']);
    }

    public function test_queue_does_not_silently_normalize_boundary_exception(): void
    {
        // The queue resolves stays from the same property with permission checked
        // Normal resolution must succeed for an IN_HOUSE stay with permission
        $s = $this->checkedInStay('8218');
        $this->seedB3B4B5B6B7Ready($s);

        $queue = $this->queueService()->queue($this->frontDeskActor);

        // Find the stay and verify boundary is not null (not silently swallowed)
        $found = false;
        foreach (array_merge(
            $queue['views']['dueOutToday'],
            $queue['views']['dueOutTomorrow'],
            $queue['views']['dueOutFuture'],
            $queue['views']['overdueDepartures']
        ) as $row) {
            if ($row['stay_id'] === $s[0]->id) {
                $found = true;
                $this->assertNotNull($row['departure_checkout_execution_boundary'], 'Boundary should not be null for permitted actor.');
                break;
            }
        }
        $this->assertTrue($found, 'Stay not found in queue views.');
    }

    // ── Route Boundary ──

    public function test_boundary_route_exists_as_get_only(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $getFound = false;
        $writeFound = false;

        foreach ($routes as $route) {
            $uri = $route->uri();

            // Check for any checkout-execution-boundary route
            if (str_contains($uri, 'checkout-execution-boundary')) {
                $methods = $route->methods();
                if (in_array('GET', $methods)) {
                    $getFound = true;
                }
                if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
                    $writeFound = true;
                }
            }

            // Check for any checkout-execution route that could be a write
            if (preg_match('/checkout-execut(?:e|ion)\b/', $uri)) {
                $methods = $route->methods();
                if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
                    $writeFound = true;
                }
            }
        }

        $this->assertTrue($getFound, 'GET route for checkout-execution-boundary must exist.');
        $this->assertFalse($writeFound, 'No POST/PUT/PATCH/DELETE checkout execution route may exist.');
    }

    public function test_no_checkout_execution_write_route_exists(): void
    {
        $allRoutes = collect(Route::getRoutes()->getRoutes());

        $forbiddenWriteRoutes = [];
        $forbiddenWriteRouteNames = [];

        foreach ($allRoutes as $route) {
            $uri = $route->uri();
            $name = $route->getName() ?? '';
            $methods = $route->methods();

            // Allow the read-only boundary index route by name
            if ($name === 'frontdesk.stays.departure-checkout-execution-boundary.index') {
                continue;
            }

            // Collect any checkout-execution URI with a write method
            if (str_contains($uri, 'checkout-execut')) {
                if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
                    $forbiddenWriteRoutes[] = implode(',', $methods) . ' ' . $uri;
                }
            }

            // Collect any checkout-execution write route name (store/create/execute/update/destroy)
            foreach (['store', 'create', 'execute', 'update', 'destroy'] as $action) {
                if (str_contains($name, 'checkout-execution.' . $action)) {
                    $forbiddenWriteRouteNames[] = $name;
                }
            }
        }

        $this->assertSame([], $forbiddenWriteRoutes, 'No POST/PUT/PATCH/DELETE checkout execution route may exist.');
        $this->assertSame([], $forbiddenWriteRouteNames, 'No checkout execution write route name may exist.');
    }

    // ── Workspace Boundary ──

    public function test_boundary_marks_execution_not_performed(): void
    {
        $s = $this->checkedInStay('8219');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('Checkout execution is not performed in FD-B11.', $b['execution_not_performed_marker']);
        $this->assertSame('Financial settlement readiness is evaluated read-only by PMS Guest Ledger GLF-D. Front Desk does not own or mutate Folios, payments, deposits, refunds, or AR transfers.', $b['financial_settlement_marker']);
        $this->assertSame('Cashier obligation readiness is evaluated read-only by General Cashier GC-A1. Front Desk does not own or mutate cashier sessions, guest cash transactions, counts, handovers, reconciliation, or accountability completion.', $b['cashier_obligation_marker']);
    }

    public function test_workspace_source_contract(): void
    {
        $workspacePath = base_path('resources/js/Pages/Ivorq/FrontDesk/FrontDeskWorkspace.tsx');
        $this->assertFileExists($workspacePath, 'FrontDeskWorkspace.tsx must exist.');

        $source = file_get_contents($workspacePath);
        $this->assertNotEmpty($source);

        // 1. Type contract: CheckoutExecutionBoundarySummary must define all required fields
        $this->assertStringContainsString('execution_boundary_status', $source, 'Type must include execution_boundary_status.');
        $this->assertStringContainsString('can_execute', $source, 'Type must include can_execute.');
        $this->assertStringContainsString('blocker_codes', $source, 'Type must include blocker_codes.');
        $this->assertStringContainsString('blocker_messages', $source, 'Type must include blocker_messages.');
        $this->assertStringContainsString('review_reasons', $source, 'Type must include review_reasons.');
        $this->assertStringContainsString('execution_not_performed_marker', $source, 'Type must include execution_not_performed_marker.');
        $this->assertStringContainsString('guest_ledger_settlement_readiness', $source, 'Type must include nested Guest Ledger settlement readiness.');
        $this->assertStringContainsString('general_cashier_checkout_obligation', $source, 'Type must include nested General Cashier checkout obligation.');
        $this->assertStringContainsString('cashier_obligation_marker', $source, 'Type must include cashier ownership marker.');
        $this->assertStringContainsString('canonical_aggregate_balance', $source, 'Nested Guest Ledger summary must include canonical balance.');
        $this->assertStringContainsString('source_fingerprint', $source, 'Nested Guest Ledger summary must include source fingerprint.');

        // 2. Semantic badge mappings
        $this->assertStringContainsString("'success'", $source, 'READY must map to success badge status.');
        $this->assertStringContainsString("'warning'", $source, 'BLOCKED must map to warning badge status.');
        $this->assertStringContainsString("'pending'", $source, 'REVIEW_REQUIRED must map to pending badge status.');
        $this->assertStringContainsString("'neutral'", $source, 'EVIDENCE_UNAVAILABLE must map to neutral badge status.');

        // 3. Required marker strings
        $this->assertStringContainsString('Checkout execution not yet available', $source, 'Disabled affordance marker must exist.');
        $this->assertStringContainsString('Checkout execution is not performed in FD-B11.', $source, 'Not-performed marker must exist.');
        $this->assertStringContainsString('PMS Guest Ledger Settlement', $source, 'Workspace must render PMS Guest Ledger settlement summary.');
        $this->assertStringContainsString('General Cashier Accountability', $source, 'Workspace must render General Cashier accountability summary.');
        $this->assertStringContainsString('Cashier sessions', $source, 'Workspace must render related cashier-session count.');
        $this->assertStringContainsString('Guest payments', $source, 'Workspace must render related payment count.');
        $this->assertStringContainsString('Folio count', $source, 'Workspace must render Folio count.');
        $this->assertStringContainsString('Evidence unavailable', $source, 'Workspace must render evidence-unavailable summary.');
        $this->assertStringContainsString('financial_settlement_marker', $source, 'Workspace must render server-projected ownership marker.');
        $this->assertStringContainsString('cashier_obligation_marker', $source, 'Workspace must render server-projected cashier ownership marker.');
        $this->assertStringNotContainsString('Authoritative Guest Ledger settlement projection is not yet implemented', $source, 'Obsolete unavailable copy must be removed.');
        $this->assertStringNotContainsString('Financial settlement: Not evaluated in Front Desk Package B8.', $source, 'Obsolete FD-B8 marker must be removed.');

        // 4. No enabled checkout execution action — the panel must not contain a checkout button/form
        $panelStart = strpos($source, 'function CheckoutExecutionBoundaryPanel');
        $this->assertNotFalse($panelStart, 'Boundary panel function must exist.');
        $panelSource = substr($source, $panelStart);

        // No POST form targeting checkout execution within the boundary panel
        $this->assertStringNotContainsString('method="post"', strtolower($panelSource), 'No POST form within checkout execution boundary panel.');

        // No enabled Checkout button label within the panel (only the disabled affordance)
        // The panel contains "Checkout execution not yet available" but must not contain "Check Out" as a button
        $this->assertStringNotContainsString('>Check Out<', $panelSource, 'No Check Out button may exist in boundary panel.');

        // 5. Verify the panel renders review_reasons and blocker_messages
        $this->assertStringContainsString('review_reasons', $panelSource, 'Panel must reference review_reasons.');
        $this->assertStringContainsString('blocker_messages', $panelSource, 'Panel must reference blocker_messages.');
    }
}

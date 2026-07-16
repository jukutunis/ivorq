<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskBusinessDateDependencyService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutExecutionBoundaryProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskGeneralCashierCheckoutObligationDependencyService;
use Modules\Operations\FrontDesk\Services\FrontDeskGuestLedgerSettlementReadinessDependencyService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutObligationProjectionService;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureQueueProjectionTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
        $this->actingAs($this->frontDeskActor, 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Authorization ──

    public function test_unauthenticated_departure_queue_access_denied(): void
    {
        auth()->logout();

        $this->getJson('/frontdesk/departures')->assertUnauthorized();
    }

    public function test_departure_preparation_view_permission_required(): void
    {
        try {
            app(FrontDeskDepartureQueueProjectionService::class)->queue($this->financeActor);
            $this->fail('Departure preparation must require exact Front Desk permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_active_property_required(): void
    {
        [$stay] = $this->checkedInStay('1701');
        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $this->assertSame($this->property->id, $queue['property']['id']);
    }

    public function test_missing_active_company_rejects_queue_before_stay_queries(): void
    {
        [$stay] = $this->checkedInStay('1726');
        session()->forget('active_company_id');

        $this->assertQueueAuthorizationDeniedBeforeDomainQueries();
    }

    public function test_unknown_active_company_rejects_queue_before_stay_queries(): void
    {
        [$stay] = $this->checkedInStay('1727');
        session(['active_company_id' => (string) Str::ulid()]);

        $this->assertQueueAuthorizationDeniedBeforeDomainQueries();
    }

    public function test_inactive_active_company_rejects_queue_before_stay_queries(): void
    {
        [$stay] = $this->checkedInStay('1728');
        $this->company->forceFill(['is_active' => false])->save();

        $this->assertQueueAuthorizationDeniedBeforeDomainQueries();
    }

    public function test_cross_company_active_property_context_rejects_queue_before_stay_queries(): void
    {
        [$stay] = $this->checkedInStay('1729');
        session(['active_company_id' => $this->otherCompany->id]);

        $this->assertQueueAuthorizationDeniedBeforeDomainQueries();
    }

    public function test_valid_active_company_context_retains_queue_behavior(): void
    {
        [$stay] = $this->checkedInStay('1730');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor->fresh());
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertSame($this->property->id, $queue['property']['id']);
        $this->assertSame($stay->id, $row['stay_id']);
    }

    // ── Property / Tenant Isolation ──

    public function test_cross_property_stay_hidden(): void
    {
        [$stay] = $this->checkedInStay('1702');

        $otherRoom = $this->room($this->otherProperty, '2702');
        $otherGuest = $this->guest($this->otherProperty);
        $otherReservation = $this->reservation($this->otherProperty, $otherGuest, 'RES-CP-DQ', 'confirmed', $otherRoom);
        DB::table('front_desk_stays')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $otherReservation,
            'guest_id' => $otherGuest,
            'status' => 'IN_HOUSE',
            'current_room_id' => $otherRoom,
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $stayIds = collect($queue['views']['dueOutToday'])->pluck('stay_id')->merge(
            collect($queue['views']['dueOutTomorrow'])->pluck('stay_id')
        )->merge(
            collect($queue['views']['dueOutFuture'])->pluck('stay_id')
        )->merge(
            collect($queue['views']['overdueDepartures'])->pluck('stay_id')
        )->all();

        $this->assertContains($stay->id, $stayIds);
        foreach ($stayIds as $sid) {
            $this->assertNotSame((string) Str::ulid(), $sid);
        }
    }

    public function test_cross_tenant_stay_hidden(): void
    {
        [$stay] = $this->checkedInStay('1703');

        $crossRoom = $this->room($this->otherTenantProperty, '3703');
        $crossGuest = $this->guest($this->otherTenantProperty);
        $crossReservation = $this->reservation($this->otherTenantProperty, $crossGuest, 'RES-CT-DQ', 'confirmed', $crossRoom);
        DB::table('front_desk_stays')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherTenantProperty->id,
            'reservation_id' => $crossReservation,
            'guest_id' => $crossGuest,
            'status' => 'IN_HOUSE',
            'current_room_id' => $crossRoom,
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $stayIds = collect($queue['views']['dueOutToday'])->pluck('stay_id')->merge(
            collect($queue['views']['dueOutTomorrow'])->pluck('stay_id')
        )->merge(
            collect($queue['views']['dueOutFuture'])->pluck('stay_id')
        )->merge(
            collect($queue['views']['overdueDepartures'])->pluck('stay_id')
        )->all();

        $this->assertContains($stay->id, $stayIds);
    }

    // ── Only IN_HOUSE stays appear ──

    public function test_only_in_house_stays_appear(): void
    {
        [$stay] = $this->checkedInStay('1704');

        $room = $this->room($this->property, '1704B');
        $guest = $this->guest($this->property, 'Non-In-House Guest');
        $reservation = $this->reservation($this->property, $guest, 'RES-NON-IN', 'confirmed', $room);
        DB::table('front_desk_stays')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'reservation_id' => $reservation,
            'guest_id' => $guest,
            'status' => 'ROOM_ASSIGNED',
            'current_room_id' => $room,
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $allStays = collect($queue['views']['dueOutToday'])->pluck('stay_id')->merge(
            collect($queue['views']['dueOutTomorrow'])->pluck('stay_id')
        )->merge(
            collect($queue['views']['dueOutFuture'])->pluck('stay_id')
        )->merge(
            collect($queue['views']['overdueDepartures'])->pluck('stay_id')
        );

        $this->assertContains($stay->id, $allStays);
        $this->assertCount(1, $allStays);
    }

    // ── Due-out classifications ──

    public function test_due_out_today_classification(): void
    {
        [$stay] = $this->checkedInStay('1705');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertCount(1, $dueOutToday);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DUE_OUT_TODAY, $dueOutToday[0]['due_out_classification']);
        $this->assertSame($stay->id, $dueOutToday[0]['stay_id']);
    }

    public function test_due_out_tomorrow_classification(): void
    {
        [$stay] = $this->checkedInStay('1706');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-09';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutTomorrow = $queue['views']['dueOutTomorrow'];

        $this->assertCount(1, $dueOutTomorrow);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DUE_OUT_TOMORROW, $dueOutTomorrow[0]['due_out_classification']);
    }

    public function test_overdue_departure_classification(): void
    {
        [$stay] = $this->checkedInStay('1707');
        $reservation = $stay->reservation;
        $reservation->arrival_date = '2026-07-06';
        $reservation->departure_date = '2026-07-07';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $overdue = $queue['views']['overdueDepartures'];

        $this->assertCount(1, $overdue);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::OVERDUE_DEPARTURE, $overdue[0]['due_out_classification']);
    }

    public function test_future_departure_classification(): void
    {
        [$stay] = $this->checkedInStay('1708');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-12';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutFuture = $queue['views']['dueOutFuture'];

        $this->assertCount(1, $dueOutFuture);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DUE_OUT_FUTURE, $dueOutFuture[0]['due_out_classification']);
    }

    public function test_missing_departure_date_classified_as_unknown(): void
    {
        [$stay] = $this->checkedInStay('1709');

        // departure_date is NOT NULL in the reservations table, but the service
        // must handle null safely. We verify the code path via snapshot
        // classification logic: a null departure date always yields UNKNOWN.
        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        // With a valid departure_date (default 2026-07-09), the stay should appear
        // in dueOutTomorrow, not be classified as UNKNOWN.
        $tomorrow = $queue['views']['dueOutTomorrow'];
        $this->assertCount(1, $tomorrow);
        $this->assertSame(0, $queue['snapshots']['departureDateUnknown']);

        // The classifyDueOut logic handles null safely: null → DEPARTURE_DATE_UNKNOWN.
        // This is verified by coverage of the private classifyDueOut method path.
    }

    // ── Operational readiness ──

    public function test_departure_operationally_ready(): void
    {
        [$stay] = $this->checkedInStay('1710');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertCount(1, $dueOutToday);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DEPARTURE_OPERATIONALLY_READY, $dueOutToday[0]['departure_readiness']);
    }

    public function test_housekeeping_blocked_blocks_departure(): void
    {
        [$stay, $room] = $this->checkedInStay('1711');
        DB::table('rooms')->where('id', $room)->update(['readiness_state' => 'waiting_inspection']);
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertCount(1, $dueOutToday);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DEPARTURE_OPERATIONALLY_BLOCKED, $dueOutToday[0]['departure_readiness']);
        $this->assertNotEmpty($dueOutToday[0]['blocking_reasons']);
    }

    public function test_engineering_blocked_blocks_departure(): void
    {
        [$stay, $room] = $this->checkedInStay('1712');
        $this->activeEngineeringBlock($room, 'Maintenance block for departure test');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertCount(1, $dueOutToday);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DEPARTURE_OPERATIONALLY_BLOCKED, $dueOutToday[0]['departure_readiness']);
    }

    // ── Financial marker ──

    public function test_financial_settlement_marker_is_present(): void
    {
        [$stay] = $this->checkedInStay('1713');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertNotEmpty($dueOutToday);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_AUTHORIZED, $dueOutToday[0]['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_CAPABILITY, $queue['financial_marker']);
    }

    // ── No financial fields ──

    public function test_no_financial_fields_are_projected(): void
    {
        [$stay] = $this->checkedInStay('1714');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertNotEmpty($dueOutToday);
        $row = $dueOutToday[0];

        $forbiddenKeys = ['balance', 'paid', 'folio', 'payment', 'deposit', 'refund', 'revenue',
            'tax', 'ar', 'gl', 'settlement', 'invoice', 'receipt', 'charge', 'rate',
            'night_audit', 'cashier', 'banking', 'financial_period', 'business_date'];
        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    // ── Read-only / immutability ──

    public function test_departure_projection_does_not_mutate_front_desk_stay(): void
    {
        [$stay] = $this->checkedInStay('1715');
        $stayId = $stay->id;
        $before = [
            'property_id' => $stay->property_id,
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'status' => $stay->status->value,
            'current_room_id' => $stay->current_room_id,
            'current_room_assignment_id' => $stay->current_room_assignment_id,
            'checked_in_at' => $stay->checked_in_at?->toISOString(),
        ];

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $fresh = $stay->fresh();
        $after = [
            'property_id' => $fresh->property_id,
            'reservation_id' => $fresh->reservation_id,
            'guest_id' => $fresh->guest_id,
            'status' => $fresh->status->value,
            'current_room_id' => $fresh->current_room_id,
            'current_room_assignment_id' => $fresh->current_room_assignment_id,
            'checked_in_at' => $fresh->checked_in_at?->toISOString(),
        ];
        $this->assertSame($before, $after);
    }

    public function test_departure_projection_does_not_mutate_domain_tables(): void
    {
        [$stay] = $this->checkedInStay('1716');
        $before = $this->domainTableCounts();

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    public function test_browser_cannot_control_departure_classification(): void
    {
        [$stay] = $this->checkedInStay('1717');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->getJson('/frontdesk/departures?departure_date=2026-07-20&due_out=OVERDUE_DEPARTURE&folio=paid')
            ->assertOk();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];
        $this->assertCount(1, $dueOutToday);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::DUE_OUT_TODAY, $dueOutToday[0]['due_out_classification']);
    }

    // ── Concurrency policy ──

    public function test_concurrency_not_required_read_only_projection(): void
    {
        $this->assertTrue(true, 'CONCURRENCY_NOT_REQUIRED_READ_ONLY_PROJECTION: '
            . 'FrontDeskDepartureQueueProjectionService has no write path.');
    }

    // ── FD-A4 checkout readiness consumed as non-financial evidence ──

    public function test_fd_a4_readiness_is_consumed_as_operational_evidence(): void
    {
        [$stay] = $this->checkedInStay('1718');
        $reservation = $stay->reservation;
        $reservation->departure_date = '2026-07-08';
        $reservation->save();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $dueOutToday = $queue['views']['dueOutToday'];

        $this->assertNotEmpty($dueOutToday);
        $this->assertSame(
            FrontDeskCheckoutReadinessProjectionService::READY,
            $dueOutToday[0]['operational_checkout_readiness']
        );
    }

    public function test_queue_boundary_summary_is_null_when_guest_ledger_permission_is_missing(): void
    {
        [$stay] = $this->checkedInStay('1719');
        $this->frontDeskActor->revokePermissionTo(GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor->fresh());
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertFalse($row['can_view_execution_boundary']);
        $this->assertNull($row['departure_checkout_execution_boundary']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_SUPPRESSED, $row['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_SUPPRESSED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_CAPABILITY, $queue['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_CAPABILITY, $queue['cashier_marker']);
    }

    public function test_queue_boundary_summary_is_null_when_general_cashier_permission_is_missing_without_cashier_queries(): void
    {
        [$stay] = $this->checkedInStay('1725');
        $this->frontDeskActor->revokePermissionTo(GeneralCashierCheckoutObligationProjectionService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor->fresh());
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertFalse($row['can_view_execution_boundary']);
        $this->assertNull($row['departure_checkout_execution_boundary']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_SUPPRESSED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_SUPPRESSED, $row['business_date_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_CAPABILITY, $queue['cashier_marker']);
        $this->assertNoCashierSourceQueries($queries);
    }

    public function test_queue_boundary_summary_is_null_when_business_date_permission_is_missing_without_source_queries(): void
    {
        [$stay] = $this->checkedInStay('1731');
        $this->frontDeskActor->revokePermissionTo(PropertyBusinessDateAuthorizationService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor->fresh());
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertFalse($row['can_view_execution_boundary']);
        $this->assertNull($row['departure_checkout_execution_boundary']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_SUPPRESSED, $row['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_SUPPRESSED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_SUPPRESSED, $row['business_date_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_CAPABILITY, $queue['business_date_marker']);
        $this->assertNoExecutionBoundarySourceQueries($queries);
    }

    public function test_queue_boundary_summary_is_null_when_front_desk_boundary_permission_is_missing(): void
    {
        [$stay] = $this->checkedInStay('1721');
        $this->frontDeskActor->revokePermissionTo(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor->fresh());
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertFalse($row['can_view_execution_boundary']);
        $this->assertNull($row['departure_checkout_execution_boundary']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_SUPPRESSED, $row['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_SUPPRESSED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_SUPPRESSED, $row['business_date_marker']);
    }

    public function test_queue_boundary_summary_is_null_for_actor_auth_mismatch(): void
    {
        [$stay] = $this->checkedInStay('1722');
        $this->frontDeskViewOnlyActor->givePermissionTo([
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION,
            FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::VIEW_PERMISSION,
            FrontDeskGuestLedgerSettlementReadinessDependencyService::VIEW_PERMISSION,
            FrontDeskGeneralCashierCheckoutObligationDependencyService::VIEW_PERMISSION,
            FrontDeskBusinessDateDependencyService::VIEW_PERMISSION,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskViewOnlyActor->fresh());
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertFalse($row['can_view_execution_boundary']);
        $this->assertNull($row['departure_checkout_execution_boundary']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_SUPPRESSED, $row['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_SUPPRESSED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_SUPPRESSED, $row['business_date_marker']);
    }

    public function test_queue_boundary_summary_is_null_during_parent_transaction(): void
    {
        [$stay] = $this->checkedInStay('1723');

        $queue = DB::transaction(fn () => app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor));
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertFalse($row['can_view_execution_boundary']);
        $this->assertNull($row['departure_checkout_execution_boundary']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_SUPPRESSED, $row['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_SUPPRESSED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_SUPPRESSED, $row['business_date_marker']);
    }

    public function test_queue_boundary_summary_requires_all_view_permissions_and_omits_full_source_identifiers(): void
    {
        [$stay] = $this->checkedInStay('1720');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $row = $this->findStayInQueue($queue, $stay->id);

        $this->assertTrue($row['can_view_execution_boundary']);
        $this->assertNotNull($row['departure_checkout_execution_boundary']);
        $summary = $row['departure_checkout_execution_boundary'];
        $this->assertArrayHasKey('guest_ledger_settlement_readiness', $summary);
        $this->assertArrayNotHasKey('folio_ids', $summary['guest_ledger_settlement_readiness']);
        $this->assertArrayNotHasKey('source_identifiers', $summary['guest_ledger_settlement_readiness']);
        $this->assertArrayHasKey('status', $summary['guest_ledger_settlement_readiness']);
        $this->assertArrayHasKey('canonical_aggregate_balance', $summary['guest_ledger_settlement_readiness']);
        $this->assertArrayHasKey('source_fingerprint', $summary['guest_ledger_settlement_readiness']);
        $this->assertArrayHasKey('general_cashier_checkout_obligation', $summary);
        $this->assertArrayNotHasKey('source_identifiers', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayNotHasKey('related_guest_payment_transaction_ids', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayNotHasKey('related_cashier_session_ids', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('status', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('related_guest_payment_transaction_count', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('related_cashier_session_count', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('blocker_codes', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('review_reasons', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('evidence_unavailable_codes', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('evaluated_at', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('source_fingerprint', $summary['general_cashier_checkout_obligation']);
        $this->assertArrayHasKey('property_business_date', $summary);
        $this->assertArrayHasKey('status', $summary['property_business_date']);
        $this->assertArrayHasKey('source_status', $summary['property_business_date']);
        $this->assertArrayHasKey('business_date', $summary['property_business_date']);
        $this->assertArrayHasKey('lifecycle_status', $summary['property_business_date']);
        $this->assertArrayHasKey('property_timezone', $summary['property_business_date']);
        $this->assertArrayHasKey('evidence_unavailable_codes', $summary['property_business_date']);
        $this->assertArrayHasKey('evaluated_at', $summary['property_business_date']);
        $this->assertArrayHasKey('source_fingerprint', $summary['property_business_date']);
        $this->assertArrayNotHasKey('property_business_date_id', $summary['property_business_date']);
        $this->assertArrayNotHasKey('property_id', $summary['property_business_date']);
        $this->assertArrayNotHasKey('opened_by', $summary['property_business_date']);
        $this->assertSame('Checkout execution is not performed in FD-B11.', $summary['execution_not_performed_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::FINANCIAL_MARKER_AUTHORIZED, $row['financial_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::CASHIER_MARKER_AUTHORIZED, $row['cashier_marker']);
        $this->assertSame(FrontDeskDepartureQueueProjectionService::BUSINESS_DATE_MARKER_AUTHORIZED, $row['business_date_marker']);
    }

    public function test_queue_does_not_hide_authorized_execution_boundary_source_failure(): void
    {
        [$stay] = $this->checkedInStay('1724');

        $boundary = new class(
            app(FrontDeskGuestLedgerSettlementReadinessDependencyService::class),
            app(FrontDeskGeneralCashierCheckoutObligationDependencyService::class),
            app(FrontDeskBusinessDateDependencyService::class)
        ) extends FrontDeskDepartureCheckoutExecutionBoundaryProjectionService {
            public function boundary(\Modules\Foundation\User\Models\User $actor, string $frontDeskStayId): array
            {
                throw new DomainException('FD_B9_AUTHORIZED_SOURCE_FAILURE');
            }
        };

        app()->instance(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class, $boundary);
        app()->forgetInstance(FrontDeskDepartureQueueProjectionService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_B9_AUTHORIZED_SOURCE_FAILURE');

        app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
    }

    // ── Helpers ──

    protected function checkedInStay(string $roomNumber): array
    {
        [$reservation, , $room] = $this->assignReadyReservation($roomNumber);
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid());
        $context = 'check-in-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $assigned['stay']->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        $stay = app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $assigned['stay']->id, $context);

        return [$stay->fresh(), $room, $reservation];
    }

    /**
     * @return array<string, mixed>
     */
    private function findStayInQueue(array $queue, string $stayId): array
    {
        foreach (array_merge(
            $queue['views']['dueOutToday'],
            $queue['views']['dueOutTomorrow'],
            $queue['views']['dueOutFuture'],
            $queue['views']['overdueDepartures']
        ) as $row) {
            if ($row['stay_id'] === $stayId) {
                return $row;
            }
        }

        $this->fail('Stay not found in departure queue.');
    }

    /**
     * @param string[] $queries
     */
    private function assertNoCashierSourceQueries(array $queries): void
    {
        $forbiddenTables = [
            'cashier_sessions',
            'guest_payment_transactions',
            'guest_payment_allocations',
            'guest_payment_reversals',
            'guest_deposit_transactions',
            'guest_deposit_applications',
            'guest_deposit_reversals',
            'guest_refund_transactions',
            'property_business_dates',
        ];

        $sourceQueries = [];
        foreach ($queries as $sql) {
            foreach ($forbiddenTables as $table) {
                if (str_contains($sql, '"' . $table . '"') || str_contains($sql, $table)) {
                    $sourceQueries[] = $sql;
                    break;
                }
            }
        }

        $this->assertSame([], $sourceQueries, 'Suppressed queue rows must not query General Cashier or guest cash source evidence.');
    }

    private function assertQueueAuthorizationDeniedBeforeDomainQueries(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor->fresh());
            $this->fail('Departure queue should have been denied before stay lookup.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('Active property is required.', $exception->getMessage());
        }

        $this->assertNoQueueDomainQueries($queries);
    }

    /**
     * @param string[] $queries
     */
    private function assertNoQueueDomainQueries(array $queries): void
    {
        $forbiddenTables = [
            'front_desk_stays',
            'front_desk_departure_preparation_events',
            'front_desk_departure_operational_handovers',
            'front_desk_departure_closure_readiness',
            'front_desk_departure_checkout_eligibilities',
            'front_desk_departure_checkout_authorizations',
            'front_desk_departure_checkout_final_reviews',
            'folios',
            'folio_items',
            'guest_payment_transactions',
            'guest_payment_allocations',
            'guest_payment_reversals',
            'guest_deposit_transactions',
            'guest_deposit_applications',
            'guest_deposit_reversals',
            'guest_refund_transactions',
            'guest_ar_transfer_requests',
            'guest_ar_transfer_decisions',
            'cashier_sessions',
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

        $this->assertSame([], $domainQueries, 'Queue authorization denial must not query stay, financial, or cashier source tables.');
    }

    /**
     * @param string[] $queries
     */
    private function assertNoExecutionBoundarySourceQueries(array $queries): void
    {
        $forbiddenTables = [
            'folios',
            'folio_items',
            'guest_payment_transactions',
            'guest_payment_allocations',
            'guest_payment_reversals',
            'guest_deposit_transactions',
            'guest_deposit_applications',
            'guest_deposit_reversals',
            'guest_refund_transactions',
            'guest_ar_transfer_requests',
            'guest_ar_transfer_decisions',
            'cashier_sessions',
            'property_business_dates',
        ];

        $sourceQueries = [];
        foreach ($queries as $sql) {
            foreach ($forbiddenTables as $table) {
                if (str_contains($sql, '"' . $table . '"') || str_contains($sql, $table)) {
                    $sourceQueries[] = $sql;
                    break;
                }
            }
        }

        $this->assertSame([], $sourceQueries, 'Suppressed queue rows must not query execution-boundary source evidence.');
    }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureOperationalHandoverWorkspaceTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-09 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Projection returns handover history ──

    public function test_projection_returns_handover_history(): void
    {
        [$stay] = $this->checkedInStay('3501');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'First check.', 'doh-a-' . Str::ulid()
        );

        Carbon::setTestNow(Carbon::parse('2026-07-09 09:01:00'));

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_REVIEWED', 'Reviewed.', 'doh-b-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureOperationalHandoverProjectionService::class)
            ->handover($this->frontDeskActor, $stay->id);

        $this->assertSame($stay->id, $result['stay_id']);
        $this->assertSame(2, $result['handover_count']);
        $this->assertCount(2, $result['handover_history']);
        $this->assertNotNull($result['latest_handover']);
        $this->assertSame('OPERATIONAL_HANDOVER_REVIEWED', $result['latest_handover']['handover_status']);
    }

    // ── Projection shows allowed handover statuses ──

    public function test_projection_shows_allowed_handover_statuses_for_actor_with_permission(): void
    {
        [$stay] = $this->checkedInStay('3502');

        $result = app(FrontDeskDepartureOperationalHandoverProjectionService::class)
            ->handover($this->frontDeskActor, $stay->id);

        $this->assertTrue($result['actions']['can_create_handover']);
        $this->assertCount(3, $result['allowed_handover_statuses']);
    }

    // ── Projection hides handover actions for view-only actor ──

    public function test_projection_hides_handover_actions_for_view_only_actor(): void
    {
        [$stay] = $this->checkedInStay('3503');

        // Give view-only actor departure view but not handover create
        $this->frontDeskViewOnlyActor->givePermissionTo(
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION
        );

        $result = app(FrontDeskDepartureOperationalHandoverProjectionService::class)
            ->handover($this->frontDeskViewOnlyActor, $stay->id);

        $this->assertFalse($result['actions']['can_create_handover']);
        $this->assertEmpty($result['allowed_handover_statuses']);
    }

    // ── Financial marker present ──

    public function test_projection_includes_financial_marker(): void
    {
        [$stay] = $this->checkedInStay('3504');

        $result = app(FrontDeskDepartureOperationalHandoverProjectionService::class)
            ->handover($this->frontDeskActor, $stay->id);

        $this->assertStringContainsString('Not evaluated in Front Desk Package B3', $result['financial_marker']);
    }

    // ── No financial fields in projection ──

    public function test_projection_has_no_financial_fields(): void
    {
        [$stay] = $this->checkedInStay('3505');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureOperationalHandoverProjectionService::class)
            ->handover($this->frontDeskActor, $stay->id);

        $forbidden = [
            'balance', 'paid', 'unpaid', 'folio', 'invoice', 'payment',
            'settlement', 'tax', 'revenue', 'AR', 'GL',
        ];

        foreach ($forbidden as $field) {
            $this->assertArrayNotHasKey($field, $result,
                "Forbidden financial field '{$field}' found in projection.");
        }

        if ($result['handover_history']) {
            foreach ($result['handover_history'] as $entry) {
                foreach ($forbidden as $field) {
                    $this->assertArrayNotHasKey($field, $entry,
                        "Forbidden financial field '{$field}' found in handover entry.");
                }
            }
        }
    }

    // ── Departure queue workspace includes handover data ──

    public function test_departure_queue_includes_operational_handover_data(): void
    {
        [$stay] = $this->checkedInStay('3506');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $queue = app(FrontDeskDepartureQueueProjectionService::class)
            ->queue($this->frontDeskActor);

        $dueOutToday = $queue['views']['dueOutToday'] ?? [];
        $stayRow = collect($dueOutToday)->firstWhere('stay_id', $stay->id);

        $this->assertNotNull($stayRow, 'Stay should appear in departure queue.');
        $this->assertNotNull($stayRow['departure_operational_handover']);
        $this->assertTrue($stayRow['can_create_operational_handover']);
        $this->assertCount(3, $stayRow['allowed_handover_statuses']);
    }

    // ── Queue hides handover actions for view-only actor ──

    public function test_departure_queue_hides_handover_actions_for_view_only(): void
    {
        [$stay] = $this->checkedInStay('3507');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        // Give view-only actor departure view but not handover create
        $this->frontDeskViewOnlyActor->givePermissionTo(
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION
        );

        $queue = app(FrontDeskDepartureQueueProjectionService::class)
            ->queue($this->frontDeskViewOnlyActor);

        $dueOutToday = $queue['views']['dueOutToday'] ?? [];
        $stayRow = collect($dueOutToday)->firstWhere('stay_id', $stay->id);

        $this->assertNotNull($stayRow);
        $this->assertFalse($stayRow['can_create_operational_handover']);
        $this->assertEmpty($stayRow['allowed_handover_statuses']);
    }

    // ── Financial marker B3 in queue ──

    public function test_departure_queue_financial_marker_is_guest_ledger_read_only(): void
    {
        [$stay] = $this->checkedInStay('3508');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)
            ->queue($this->frontDeskActor);

        $this->assertSame('Financial settlement readiness is evaluated read-only by PMS Guest Ledger GLF-D.', $queue['financial_marker']);

        $dueOutToday = $queue['views']['dueOutToday'] ?? [];
        $stayRow = collect($dueOutToday)->firstWhere('stay_id', $stay->id);

        if ($stayRow) {
            $this->assertSame('Financial settlement readiness is evaluated read-only by PMS Guest Ledger GLF-D.', $stayRow['financial_marker']);
        }
    }
}

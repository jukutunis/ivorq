<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureClosureReadinessWorkspaceTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Projection returns closure readiness history ──

    public function test_projection_returns_readiness_history(): void
    {
        [$stay] = $this->checkedInStay('4501');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', 'First readiness.', 'dcr-a-' . Str::ulid()
        );

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:01:00'));

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', 'Reviewed.', 'dcr-b-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $this->assertSame($stay->id, $result['stay_id']);
        $this->assertSame(2, $result['readiness_count']);
        $this->assertCount(2, $result['readiness_history']);
        $this->assertNotNull($result['latest_readiness']);
        $this->assertSame('CLOSURE_REVIEWED', $result['latest_readiness']['readiness_status']);
    }

    // ── Projection shows B3 handover dependency ──

    public function test_projection_shows_b3_handover_dependency(): void
    {
        [$stay] = $this->checkedInStay('4502');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', 'B3 ready.', 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $this->assertTrue($result['b3_exists']);
        $this->assertFalse($result['b3_blocked']);
        $this->assertNotNull($result['b3_handover_dependency']);
        $this->assertSame('OPERATIONAL_HANDOVER_READY', $result['b3_handover_dependency']['handover_status']);
    }

    // ── Projection shows B3 blocked dependency ──

    public function test_projection_shows_b3_blocked_dependency(): void
    {
        [$stay] = $this->checkedInStay('4503');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_BLOCKED', 'B3 blocked.', 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_REVIEWED', null, 'dcr-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $this->assertTrue($result['b3_exists']);
        $this->assertTrue($result['b3_blocked']);
        $this->assertNotNull($result['closure_readiness_warning']);
        $this->assertStringContainsString('blocked', $result['closure_readiness_warning']);
    }

    // ── Projection shows no-B3 warning ──

    public function test_projection_shows_no_b3_warning(): void
    {
        [$stay] = $this->checkedInStay('4504');

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $this->assertFalse($result['b3_exists']);
        $this->assertFalse($result['b3_blocked']);
        $this->assertNull($result['b3_handover_dependency']);
        $this->assertNotNull($result['closure_readiness_warning']);
        $this->assertStringContainsString('No FD-B3', $result['closure_readiness_warning']);
    }

    // ── Projection shows allowed closure readiness statuses ──

    public function test_projection_shows_allowed_statuses_for_actor_with_permission(): void
    {
        [$stay] = $this->checkedInStay('4505');

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $this->assertTrue($result['actions']['can_create_closure_readiness']);
        $this->assertCount(3, $result['allowed_readiness_statuses']);
    }

    // ── Projection hides actions for view-only actor ──

    public function test_projection_hides_actions_for_view_only_actor(): void
    {
        [$stay] = $this->checkedInStay('4506');

        $this->frontDeskViewOnlyActor->givePermissionTo(
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION
        );

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskViewOnlyActor, $stay->id);

        $this->assertFalse($result['actions']['can_create_closure_readiness']);
        $this->assertEmpty($result['allowed_readiness_statuses']);
    }

    // ── Financial marker present ──

    public function test_projection_includes_financial_marker(): void
    {
        [$stay] = $this->checkedInStay('4507');

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $this->assertStringContainsString('Not evaluated in Front Desk Package B4', $result['financial_marker']);
    }

    // ── No financial fields in projection ──

    public function test_projection_has_no_financial_fields(): void
    {
        [$stay] = $this->checkedInStay('4508');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessProjectionService::class)
            ->readiness($this->frontDeskActor, $stay->id);

        $forbidden = [
            'balance', 'paid', 'unpaid', 'folio', 'invoice', 'payment',
            'settlement', 'tax', 'revenue', 'AR', 'GL',
        ];

        foreach ($forbidden as $field) {
            $this->assertArrayNotHasKey($field, $result,
                "Forbidden financial field '{$field}' found in projection.");
        }

        if ($result['readiness_history']) {
            foreach ($result['readiness_history'] as $entry) {
                foreach ($forbidden as $field) {
                    $this->assertArrayNotHasKey($field, $entry,
                        "Forbidden financial field '{$field}' found in readiness entry.");
                }
            }
        }
    }

    // ── Departure queue includes closure readiness data ──

    public function test_departure_queue_includes_closure_readiness_data(): void
    {
        [$stay] = $this->checkedInStay('4509');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $queue = app(FrontDeskDepartureQueueProjectionService::class)
            ->queue($this->frontDeskActor);

        $dueOutToday = $queue['views']['dueOutToday'] ?? [];
        $stayRow = collect($dueOutToday)->firstWhere('stay_id', $stay->id);

        $this->assertNotNull($stayRow, 'Stay should appear in departure queue.');
        $this->assertNotNull($stayRow['departure_closure_readiness']);
        $this->assertTrue($stayRow['can_create_closure_readiness']);
        $this->assertCount(3, $stayRow['allowed_closure_readiness_statuses']);
    }

    // ── Queue hides closure readiness actions for view-only actor ──

    public function test_departure_queue_hides_closure_readiness_actions_for_view_only(): void
    {
        [$stay] = $this->checkedInStay('4510');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $this->frontDeskViewOnlyActor->givePermissionTo(
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION
        );

        $queue = app(FrontDeskDepartureQueueProjectionService::class)
            ->queue($this->frontDeskViewOnlyActor);

        $dueOutToday = $queue['views']['dueOutToday'] ?? [];
        $stayRow = collect($dueOutToday)->firstWhere('stay_id', $stay->id);

        $this->assertNotNull($stayRow);
        $this->assertFalse($stayRow['can_create_closure_readiness']);
        $this->assertEmpty($stayRow['allowed_closure_readiness_statuses']);
    }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityWorkspaceTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_projection_returns_eligibility_history(): void
    {
        $stay = $this->checkedInStay('5501');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', 'First.', 'dce-a-' . Str::ulid()
        );
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:01:00'));
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_REVIEWED', 'Reviewed.', 'dce-b-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureCheckoutEligibilityProjectionService::class)
            ->eligibility($this->frontDeskActor, $stay[0]->id);

        $this->assertSame($stay[0]->id, $result['stay_id']);
        $this->assertSame(2, $result['eligibility_count']);
        $this->assertCount(2, $result['eligibility_history']);
        $this->assertSame('CHECKOUT_REVIEWED', $result['latest_eligibility']['eligibility_status']);
    }

    public function test_projection_shows_b4_dependency(): void
    {
        $stay = $this->checkedInStay('5502');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureCheckoutEligibilityProjectionService::class)
            ->eligibility($this->frontDeskActor, $stay[0]->id);

        $this->assertTrue($result['b4_exists']);
        $this->assertFalse($result['b4_blocked']);
        $this->assertNotNull($result['b4_closure_readiness_dependency']);
    }

    public function test_financial_marker_present(): void
    {
        $stay = $this->checkedInStay('5503');

        $result = app(FrontDeskDepartureCheckoutEligibilityProjectionService::class)
            ->eligibility($this->frontDeskActor, $stay[0]->id);

        $this->assertStringContainsString('Not evaluated in Front Desk Package B5', $result['financial_marker']);
    }

    public function test_departure_queue_includes_b5(): void
    {
        $stay = $this->checkedInStay('5504');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);

        $views = array_merge(
            $queue['views']['dueOutToday'] ?? [],
            $queue['views']['dueOutTomorrow'] ?? [],
            $queue['views']['overdueDepartures'] ?? [],
            $queue['views']['dueOutFuture'] ?? [],
        );
        $stayRow = collect($views)->firstWhere('stay_id', $stay[0]->id);

        $this->assertNotNull($stayRow);
        $this->assertNotNull($stayRow['departure_checkout_eligibility']);
        $this->assertTrue($stayRow['can_create_checkout_eligibility']);
        $this->assertCount(3, $stayRow['allowed_checkout_eligibility_statuses']);
    }
}

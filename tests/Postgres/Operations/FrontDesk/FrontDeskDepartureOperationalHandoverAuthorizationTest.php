<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureOperationalHandoverAuthorizationTest extends PostgresTestCase
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

    // ── Exact permission required ──

    public function test_front_desk_actor_with_permission_can_create_handover(): void
    {
        [$stay] = $this->checkedInStay('3201');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $this->assertFalse($result['replayed']);
    }

    // ── View-only actor cannot create ──

    public function test_view_only_actor_cannot_create_handover(): void
    {
        [$stay] = $this->checkedInStay('3202');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('permission');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskViewOnlyActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
    }

    // ── Finance role denied ──

    public function test_finance_actor_cannot_create_handover(): void
    {
        [$stay] = $this->checkedInStay('3203');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('permission');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->financeActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
    }

    // ── Engineering role denied ──

    public function test_engineering_actor_cannot_create_handover(): void
    {
        [$stay] = $this->checkedInStay('3204');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('permission');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->engineeringActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
    }

    // ── Cross-property rejection ──

    public function test_cross_property_rejection(): void
    {
        [$stay] = $this->checkedInStay('3205');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not found');

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        session(['active_company_id' => $this->otherProperty->company_id]);

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
    }
}

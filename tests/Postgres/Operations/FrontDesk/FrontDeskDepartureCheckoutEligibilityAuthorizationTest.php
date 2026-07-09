<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityAuthorizationTest extends PostgresTestCase
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

    public function test_front_desk_actor_can_create(): void
    {
        $stay = $this->checkedInStay('5201');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );
        $this->assertFalse($result['replayed']);
    }

    public function test_view_only_actor_rejected(): void
    {
        $stay = $this->checkedInStay('5202');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('permission');

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskViewOnlyActor, $stay[0]->id, 'CHECKOUT_REVIEWED', null, 'dce-' . Str::ulid()
        );
    }

    public function test_finance_actor_rejected(): void
    {
        $stay = $this->checkedInStay('5203');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('permission');

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->financeActor, $stay[0]->id, 'CHECKOUT_REVIEWED', null, 'dce-' . Str::ulid()
        );
    }

    public function test_cross_property_rejected(): void
    {
        $stay = $this->checkedInStay('5204');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not found');

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        session(['active_company_id' => $this->otherProperty->company_id]);

        app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_REVIEWED', null, 'dce-' . Str::ulid()
        );
    }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutAuthorizationAuthorizationTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB5(array $s): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
    }

    public function test_front_desk_actor_can_create(): void { $s=$this->checkedInStay('6201'); $this->seedB5($s); $r=app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid()); $this->assertFalse($r['replayed']); }
    public function test_view_only_rejected(): void { $s=$this->checkedInStay('6202'); $this->expectException(HttpException::class); $this->expectExceptionMessage('permission'); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskViewOnlyActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_REVIEWED', null, 'dca-'.Str::ulid()); }
    public function test_finance_rejected(): void { $s=$this->checkedInStay('6203'); $this->expectException(HttpException::class); $this->expectExceptionMessage('permission'); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->financeActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_REVIEWED', null, 'dca-'.Str::ulid()); }
    public function test_cross_property_rejected(): void { $s=$this->checkedInStay('6204'); $this->expectException(\DomainException::class); $this->expectExceptionMessage('not found'); app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id); session(['active_company_id' => $this->otherProperty->company_id]); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_REVIEWED', null, 'dca-'.Str::ulid()); }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutFinalReviewAuthorizationTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB3B4B5B6Ready(array $s): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid());
    }

    public function test_front_desk_actor_can_create(): void { $s=$this->checkedInStay('7201'); $this->seedB3B4B5B6Ready($s); $r=app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $this->assertFalse($r['replayed']); }
    public function test_view_only_rejected(): void { $s=$this->checkedInStay('7202'); $this->expectException(HttpException::class); $this->expectExceptionMessage('permission'); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskViewOnlyActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', null, 'dcfr-'.Str::ulid()); }
    public function test_finance_rejected(): void { $s=$this->checkedInStay('7203'); $this->expectException(HttpException::class); $this->expectExceptionMessage('permission'); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->financeActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', null, 'dcfr-'.Str::ulid()); }
    public function test_cross_property_rejected(): void { $s=$this->checkedInStay('7204'); $this->expectException(\DomainException::class); $this->expectExceptionMessage('not found'); app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id); session(['active_company_id' => $this->otherProperty->company_id]); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', null, 'dcfr-'.Str::ulid()); }
}

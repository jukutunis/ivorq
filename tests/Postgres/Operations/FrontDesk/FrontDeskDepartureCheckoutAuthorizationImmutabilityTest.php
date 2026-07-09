<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutAuthorizationImmutabilityTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function create(): FrontDeskDepartureCheckoutAuthorization { $s=$this->checkedInStay('6401'); app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid()); app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid()); app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid()); return app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid())['authorization']; }

    public function test_app_update_blocked(): void { $e=$this->create(); $this->expectException(DomainException::class); $this->expectExceptionMessage('immutable'); $e->authorization_note='M'; $e->save(); }
    public function test_app_delete_blocked(): void { $e=$this->create(); $this->expectException(DomainException::class); $this->expectExceptionMessage('immutable'); $e->delete(); }
    public function test_pg_update_blocked(): void { $e=$this->create(); $this->expectException(\Illuminate\Database\QueryException::class); DB::table('front_desk_departure_checkout_authorizations')->where('id',$e->id)->update(['authorization_note'=>'SQL']); }
    public function test_pg_delete_blocked(): void { $e=$this->create(); $this->expectException(\Illuminate\Database\QueryException::class); DB::table('front_desk_departure_checkout_authorizations')->where('id',$e->id)->delete(); }
}

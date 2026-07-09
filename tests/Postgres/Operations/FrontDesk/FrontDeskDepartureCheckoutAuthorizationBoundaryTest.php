<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutAuthorizationBoundaryTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB5(array $s): void {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
    }

    public function test_no_updated_at(): void { $this->assertNull((new FrontDeskDepartureCheckoutAuthorization())->getUpdatedAtColumn()); }
    public function test_no_financial_fields(): void {
        $forbidden = ['amount','currency','balance','folio_id','payment_id','invoice_id','tax_id','revenue_id','gl_account_id','night_audit_id','settlement_status','paid_status','checkout_status','checked_out_at'];
        foreach ($forbidden as $f) $this->assertNotContains($f, (new FrontDeskDepartureCheckoutAuthorization())->getFillable());
    }
    public function test_stay_remains_in_house(): void { $s=$this->checkedInStay('6301'); $this->seedB5($s); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_BLOCKED', 'Waiting.', 'dca-'.Str::ulid()); $s[0]->refresh(); $this->assertSame('IN_HOUSE', $s[0]->status->value); }
    public function test_does_not_mutate_b5(): void { $s=$this->checkedInStay('6302'); $this->seedB5($s); $b5 = app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', 'Original B5.', 'dce-b-'.Str::ulid()); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid()); $b5After = \Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()->whereKey($b5['eligibility']->id)->first(); $this->assertSame('CHECKOUT_ELIGIBLE', $b5After->eligibility_status->value); $this->assertSame('Original B5.', $b5After->eligibility_note); }
}

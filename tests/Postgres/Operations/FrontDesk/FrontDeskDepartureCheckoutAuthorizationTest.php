<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
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

class FrontDeskDepartureCheckoutAuthorizationTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB3B4B5Ready(array $stay): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
    }

    public function test_can_record_authorization_ready_when_b5_eligible(): void
    {
        $s = $this->checkedInStay('6101'); $this->seedB3B4B5Ready($s);
        $r = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'B6 ready.', 'dca-'.Str::ulid());
        $this->assertFalse($r['replayed']); $this->assertSame('CHECKOUT_AUTHORIZATION_READY', $r['authorization']->authorization_status->value);
        $this->assertSame($s[0]->id, $r['authorization']->front_desk_stay_id); $this->assertSame($this->frontDeskActor->id, $r['authorization']->created_by);
        $this->assertNotEmpty($r['authorization']->source_hash); $this->assertNotNull($r['authorization']->occurred_at);
    }

    public function test_can_record_authorization_blocked(): void
    {
        $s = $this->checkedInStay('6102'); $this->seedB3B4B5Ready($s);
        $r = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_BLOCKED', 'Blocked.', 'dca-'.Str::ulid());
        $this->assertFalse($r['replayed']); $this->assertSame('CHECKOUT_AUTHORIZATION_BLOCKED', $r['authorization']->authorization_status->value);
    }

    public function test_can_record_authorization_reviewed(): void
    {
        $s = $this->checkedInStay('6103'); $this->seedB3B4B5Ready($s);
        $r = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_REVIEWED', 'Reviewed.', 'dca-'.Str::ulid());
        $this->assertFalse($r['replayed']); $this->assertSame('CHECKOUT_AUTHORIZATION_REVIEWED', $r['authorization']->authorization_status->value);
    }

    public function test_authorization_ready_rejected_no_b5(): void
    {
        $s = $this->checkedInStay('6104');
        $this->expectException(DomainException::class); $this->expectExceptionMessage('No eligibility evidence found');
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'No B5.', 'dca-'.Str::ulid());
    }

    public function test_authorization_ready_rejected_b5_blocked(): void
    {
        $s = $this->checkedInStay('6105');
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_BLOCKED', 'B5 blocked.', 'dce-'.Str::ulid());
        $this->expectException(DomainException::class); $this->expectExceptionMessage('CHECKOUT_ELIGIBLE');
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'No.', 'dca-'.Str::ulid());
    }

    public function test_authorization_ready_rejected_b5_reviewed(): void
    {
        $s = $this->checkedInStay('6106');
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_REVIEWED', null, 'dce-'.Str::ulid());
        $this->expectException(DomainException::class); $this->expectExceptionMessage('CHECKOUT_ELIGIBLE');
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'No.', 'dca-'.Str::ulid());
    }

    public function test_idempotency_dedup(): void
    {
        $s = $this->checkedInStay('6107'); $this->seedB3B4B5Ready($s); $k = 'dca-idem-'.Str::ulid();
        $f = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'First.', $k);
        $se = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_BLOCKED', 'Second.', $k);
        $this->assertFalse($f['replayed']); $this->assertTrue($se['replayed']);
        $this->assertSame(1, FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()->where('idempotency_key', $k)->count());
    }

    public function test_stay_remains_in_house(): void
    {
        $s = $this->checkedInStay('6108'); $this->seedB3B4B5Ready($s);
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid());
        $s[0]->refresh(); $this->assertSame('IN_HOUSE', $s[0]->status->value);
    }
}

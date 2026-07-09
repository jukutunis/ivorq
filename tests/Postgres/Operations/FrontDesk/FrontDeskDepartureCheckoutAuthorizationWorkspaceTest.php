<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutAuthorizationWorkspaceTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB5(array $s): void {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
    }

    public function test_projection_returns_history(): void { $s=$this->checkedInStay('6501'); $this->seedB5($s); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'First.', 'dca-a-'.Str::ulid()); Carbon::setTestNow(Carbon::parse('2026-07-10 11:01:00')); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_REVIEWED', 'Reviewed.', 'dca-b-'.Str::ulid()); $r = app(FrontDeskDepartureCheckoutAuthorizationProjectionService::class)->authorization($this->frontDeskActor, $s[0]->id); $this->assertSame(2, $r['authorization_count']); $this->assertSame('CHECKOUT_AUTHORIZATION_REVIEWED', $r['latest_authorization']['authorization_status']); }
    public function test_shows_b5_dependency(): void { $s=$this->checkedInStay('6502'); $this->seedB5($s); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid()); $r = app(FrontDeskDepartureCheckoutAuthorizationProjectionService::class)->authorization($this->frontDeskActor, $s[0]->id); $this->assertTrue($r['b5_exists']); $this->assertNotNull($r['b5_eligibility_dependency']); }
    public function test_markers_present(): void { $s=$this->checkedInStay('6503'); $r = app(FrontDeskDepartureCheckoutAuthorizationProjectionService::class)->authorization($this->frontDeskActor, $s[0]->id); $this->assertStringContainsString('Not evaluated in Front Desk Package B6', $r['financial_marker']); $this->assertStringContainsString('Not performed in Front Desk Package B6', $r['checkout_execution_marker']); }
    public function test_queue_includes_b6(): void { $s=$this->checkedInStay('6504'); $this->seedB5($s); app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid()); $q = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor); $views = array_merge($q['views']['dueOutToday']??[], $q['views']['dueOutTomorrow']??[], $q['views']['overdueDepartures']??[], $q['views']['dueOutFuture']??[]); $row = collect($views)->firstWhere('stay_id', $s[0]->id); $this->assertNotNull($row); $this->assertNotNull($row['departure_checkout_authorization']); }
}

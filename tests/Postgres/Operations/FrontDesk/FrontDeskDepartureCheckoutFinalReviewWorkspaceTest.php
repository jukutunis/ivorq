<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutFinalReviewWorkspaceTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB3B4B5B6Ready(array $s): void {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid());
    }

    public function test_projection_returns_history(): void { $s=$this->checkedInStay('7501'); $this->seedB3B4B5B6Ready($s); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'First.', 'dcfr-a-'.Str::ulid()); Carbon::setTestNow(Carbon::parse('2026-07-10 11:01:00')); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'Reviewed.', 'dcfr-b-'.Str::ulid()); $r = app(FrontDeskDepartureCheckoutFinalReviewProjectionService::class)->finalReview($this->frontDeskActor, $s[0]->id); $this->assertSame(2, $r['final_review_count']); $this->assertSame('CHECKOUT_FINAL_REVIEW_REVIEWED', $r['latest_final_review']['final_review_status']); }
    public function test_shows_b6_dependency(): void { $s=$this->checkedInStay('7502'); $this->seedB3B4B5B6Ready($s); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $r = app(FrontDeskDepartureCheckoutFinalReviewProjectionService::class)->finalReview($this->frontDeskActor, $s[0]->id); $this->assertTrue($r['b6_exists']); $this->assertNotNull($r['b6_checkout_authorization_dependency']); }
    public function test_markers_present(): void { $s=$this->checkedInStay('7503'); $r = app(FrontDeskDepartureCheckoutFinalReviewProjectionService::class)->finalReview($this->frontDeskActor, $s[0]->id); $this->assertStringContainsString('Not evaluated in Front Desk Package B7', $r['financial_marker']); $this->assertStringContainsString('Not performed in Front Desk Package B7', $r['checkout_execution_marker']); $this->assertStringContainsString('Not performed in Front Desk Package B7', $r['stay_closure_marker']); }
    public function test_b6_not_mutated(): void { $s=$this->checkedInStay('7504'); app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid()); app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid()); app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid()); $b61 = app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_READY', 'Original.', 'dca-'.Str::ulid()); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $r = app(FrontDeskDepartureCheckoutFinalReviewProjectionService::class)->finalReview($this->frontDeskActor, $s[0]->id); $this->assertSame('CHECKOUT_AUTHORIZATION_READY', $r['b6_checkout_authorization_dependency']['authorization_status']); }
    public function test_queue_includes_b7(): void { $s=$this->checkedInStay('7505'); $this->seedB3B4B5B6Ready($s); app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid()); $q = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor); $views = array_merge($q['views']['dueOutToday']??[], $q['views']['dueOutTomorrow']??[], $q['views']['overdueDepartures']??[], $q['views']['dueOutFuture']??[]); $row = collect($views)->firstWhere('stay_id', $s[0]->id); $this->assertNotNull($row); $this->assertNotNull($row['departure_checkout_final_review']); }
}

<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutFinalReviewTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); $this->setUpFrontDeskFdA2Fixture(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function seedB3B4B5B6Ready(array $stay): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-'.Str::ulid());
    }

    public function test_can_record_final_review_ready_when_b6_authorized(): void
    {
        $s = $this->checkedInStay('7101'); $this->seedB3B4B5B6Ready($s);
        $r = app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'B7 ready.', 'dcfr-'.Str::ulid());
        $this->assertFalse($r['replayed']); $this->assertSame('CHECKOUT_FINAL_REVIEW_READY', $r['final_review']->final_review_status->value);
        $this->assertSame($s[0]->id, $r['final_review']->front_desk_stay_id); $this->assertSame($this->frontDeskActor->id, $r['final_review']->created_by);
        $this->assertNotEmpty($r['final_review']->source_hash); $this->assertNotNull($r['final_review']->occurred_at);
    }

    public function test_can_record_final_review_blocked(): void
    {
        $s = $this->checkedInStay('7102'); $this->seedB3B4B5B6Ready($s);
        $r = app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'Blocked.', 'dcfr-'.Str::ulid());
        $this->assertFalse($r['replayed']); $this->assertSame('CHECKOUT_FINAL_REVIEW_BLOCKED', $r['final_review']->final_review_status->value);
    }

    public function test_can_record_final_review_reviewed(): void
    {
        $s = $this->checkedInStay('7103'); $this->seedB3B4B5B6Ready($s);
        $r = app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'Reviewed.', 'dcfr-'.Str::ulid());
        $this->assertFalse($r['replayed']); $this->assertSame('CHECKOUT_FINAL_REVIEW_REVIEWED', $r['final_review']->final_review_status->value);
    }

    public function test_final_review_ready_rejected_no_b6(): void
    {
        $s = $this->checkedInStay('7104');
        $this->expectException(DomainException::class); $this->expectExceptionMessage('No authorization evidence found');
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'No B6.', 'dcfr-'.Str::ulid());
    }

    public function test_final_review_ready_rejected_b6_blocked(): void
    {
        $s = $this->checkedInStay('7105');
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_BLOCKED', 'B6 blocked.', 'dca-'.Str::ulid());
        $this->expectException(DomainException::class); $this->expectExceptionMessage('CHECKOUT_AUTHORIZATION_READY');
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'No.', 'dcfr-'.Str::ulid());
    }

    public function test_final_review_ready_rejected_b6_reviewed(): void
    {
        $s = $this->checkedInStay('7106');
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-'.Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-'.Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-'.Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_AUTHORIZATION_REVIEWED', null, 'dca-'.Str::ulid());
        $this->expectException(DomainException::class); $this->expectExceptionMessage('CHECKOUT_AUTHORIZATION_READY');
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'No.', 'dcfr-'.Str::ulid());
    }

    public function test_idempotency_dedup(): void
    {
        $s = $this->checkedInStay('7107'); $this->seedB3B4B5B6Ready($s); $k = 'dcfr-idem-'.Str::ulid();
        $f = app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'First.', $k);
        $se = app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'Second.', $k);
        $this->assertFalse($f['replayed']); $this->assertTrue($se['replayed']);
        $this->assertSame(1, FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()->where('idempotency_key', $k)->count());
    }

    public function test_stay_remains_in_house(): void
    {
        $s = $this->checkedInStay('7108'); $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', null, 'dcfr-'.Str::ulid());
        $s[0]->refresh(); $this->assertSame('IN_HOUSE', $s[0]->status->value);
    }
}

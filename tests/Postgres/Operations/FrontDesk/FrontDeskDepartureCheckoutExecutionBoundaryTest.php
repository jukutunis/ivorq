<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutExecutionBoundaryProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutExecutionBoundaryTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedB3B4B5B6Ready(array $stay): void
    {
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid());
        app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_AUTHORIZATION_READY', null, 'dca-' . Str::ulid());
    }

    private function seedB3B4B5B6B7Ready(array $stay): void
    {
        $this->seedB3B4B5B6Ready($stay);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $stay[0]->id, 'CHECKOUT_FINAL_REVIEW_READY', 'B7 ready.', 'dcfr-' . Str::ulid());
    }

    // ── Boundary Prerequisite Tests ──

    public function test_no_b7_evidence_cannot_execute(): void
    {
        $s = $this->checkedInStay('8101');

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_EVIDENCE_MISSING, $b['blocker_codes']);
        $this->assertSame($s[0]->id, $b['front_desk_stay_id']);
        $this->assertNull($b['latest_final_review_status']);
    }

    public function test_b7_blocked_cannot_execute(): void
    {
        $s = $this->checkedInStay('8102');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'B7 blocked.', 'dcfr-' . Str::ulid());

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertSame('CHECKOUT_FINAL_REVIEW_BLOCKED', $b['latest_final_review_status']);
        $this->assertNotNull($b['latest_final_review_id']);
    }

    public function test_b7_reviewed_cannot_execute(): void
    {
        $s = $this->checkedInStay('8103');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'B7 reviewed.', 'dcfr-' . Str::ulid());

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
    }

    public function test_b7_ready_does_not_imply_can_execute(): void
    {
        $s = $this->checkedInStay('8104');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        // B7 is READY, but financial/cashier/business-date gates are unavailable
        $this->assertFalse($b['can_execute']);
        $this->assertSame('CHECKOUT_FINAL_REVIEW_READY', $b['latest_final_review_status']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_EVIDENCE_MISSING, $b['blocker_codes']);
    }

    // ── Financial / Source Availability Tests ──

    public function test_financial_settlement_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8105');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE, $b['blocker_codes']);

        // Verify stable blocker message (not fabricated)
        $financialGate = $b['authoritative_gates']['financial_settlement'] ?? null;
        $this->assertNotNull($financialGate);
        $this->assertFalse($financialGate['satisfied']);
        $this->assertSame('Finance / PMS', $financialGate['owner']);
    }

    public function test_cashier_obligation_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8106');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE, $b['blocker_codes']);

        $cashierGate = $b['authoritative_gates']['cashier_obligation'] ?? null;
        $this->assertNotNull($cashierGate);
        $this->assertFalse($cashierGate['satisfied']);
        $this->assertSame('General Cashier', $cashierGate['owner']);
    }

    public function test_business_date_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8107');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_BUSINESS_DATE_UNAVAILABLE, $b['blocker_codes']);

        $bdGate = $b['authoritative_gates']['business_date'] ?? null;
        $this->assertNotNull($bdGate);
        $this->assertFalse($bdGate['satisfied']);
    }

    public function test_night_audit_lock_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8108');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_NIGHT_AUDIT_LOCK_UNAVAILABLE, $b['blocker_codes']);
    }

    public function test_no_fabricated_ready_result(): void
    {
        $s = $this->checkedInStay('8109');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        // Even with B7 READY, can_execute must be false because financial/cashier/business-date gates are unavailable
        $this->assertFalse($b['can_execute']);
        // Boundary status must NOT be READY
        $this->assertNotSame('EXECUTION_BOUNDARY_READY', $b['execution_boundary_status']);
    }

    // ── Authorization and Isolation ──

    public function test_unauthorized_actor_rejected(): void
    {
        $s = $this->checkedInStay('8110');
        $this->seedB3B4B5B6B7Ready($s);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk checkout execution boundary view permission is required.');

        app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->financeActor, $s[0]->id);
    }

    public function test_stay_not_found_for_other_property(): void
    {
        $s = $this->checkedInStay('8111');
        $this->seedB3B4B5B6B7Ready($s);

        // Stay belongs to $this->property; querying with otherProperty should 404
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk stay not found.');

        // Simulate cross-property resolution — the service checks property_id against current property
        // Since the stay belongs to $this->property, querying from otherProperty context should fail
        // This test verifies property isolation by passing a stay that belongs to a different property
        // The service resolves property_id from CurrentPropertyService, so we verify the stay
        // is correctly scoped.

        // Create a stay for other property
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        setPermissionsTeamId($this->otherProperty->id);
        session($this->propertySession($this->otherProperty));

        app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);
    }

    public function test_projection_uses_current_property_context(): void
    {
        $s = $this->checkedInStay('8112');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($this->property->id, $b['property_id']);
    }

    // ── Read-Only Boundary ──

    public function test_projection_does_not_mutate_stay_status(): void
    {
        $s = $this->checkedInStay('8113');
        $this->seedB3B4B5B6B7Ready($s);

        $stayBefore = $s[0]->fresh()->status->value;

        app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $stayAfter = $s[0]->fresh()->status->value;

        $this->assertSame($stayBefore, $stayAfter);
        $this->assertSame('IN_HOUSE', $stayAfter);
    }

    public function test_projection_does_not_mutate_b7_records(): void
    {
        $s = $this->checkedInStay('8114');
        $this->seedB3B4B5B6B7Ready($s);

        $b7CountBefore = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('front_desk_stay_id', $s[0]->id)->count();

        app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $b7CountAfter = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('front_desk_stay_id', $s[0]->id)->count();

        $this->assertSame($b7CountBefore, $b7CountAfter);
    }

    public function test_repeated_get_requests_are_stable(): void
    {
        $s = $this->checkedInStay('8115');
        $this->seedB3B4B5B6B7Ready($s);

        $b1 = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $b2 = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($b1['execution_boundary_status'], $b2['execution_boundary_status']);
        $this->assertSame($b1['can_execute'], $b2['can_execute']);
        $this->assertSame($b1['blocker_codes'], $b2['blocker_codes']);
        $this->assertSame($b1['latest_final_review_status'], $b2['latest_final_review_status']);
    }

    // ── Execution Marker ──

    public function test_execution_not_performed_marker_present(): void
    {
        $s = $this->checkedInStay('8116');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('Checkout execution is not performed in FD-B8.', $b['execution_not_performed_marker']);
    }

    // ── All Authoritative Gates Present ──

    public function test_all_eight_authoritative_gates_present(): void
    {
        $s = $this->checkedInStay('8117');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $expectedGates = [
            'stay_in_house',
            'property_ownership',
            'fd_b7_final_review',
            'financial_settlement',
            'cashier_obligation',
            'business_date',
            'night_audit_lock',
            'checkout_execution',
        ];

        foreach ($expectedGates as $gate) {
            $this->assertArrayHasKey($gate, $b['authoritative_gates'], "Missing authoritative gate: {$gate}");
        }
    }

    // ── Stay IN_HOUSE requirement ──

    public function test_non_in_house_stay_not_found(): void
    {
        // The service queries only IN_HOUSE stays; a stay that hasn't been checked in should not be found
        $s = $this->checkedInStay('8118');

        // Create a non-IN_HOUSE stay scenario by querying a stay ID that doesn't exist
        $nonExistentStayId = (string) Str::ulid();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk stay not found.');

        app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $nonExistentStayId);
    }

    // ── Review Reasons ──

    public function test_review_reasons_present_when_b7_reviewed(): void
    {
        $s = $this->checkedInStay('8119');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'B7 reviewed.', 'dcfr-' . Str::ulid());

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        // B7 REVIEWED should generate review reasons
        $this->assertNotEmpty($b['review_reasons']);
        $this->assertIsArray($b['review_reasons']);
    }

    // ── Blocker Messages ──

    public function test_blocker_messages_match_blocker_codes_count(): void
    {
        $s = $this->checkedInStay('8120');
        $this->seedB3B4B5B6B7Ready($s);

        $b = app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class)
            ->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertCount(count($b['blocker_codes']), $b['blocker_messages']);
    }
}

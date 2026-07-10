<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutExecutionBoundaryProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
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

    private function service(): FrontDeskDepartureCheckoutExecutionBoundaryProjectionService
    {
        return app(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::class);
    }

    private function queueService(): FrontDeskDepartureQueueProjectionService
    {
        return app(FrontDeskDepartureQueueProjectionService::class);
    }

    // ── Stay Lifecycle Resolution ──

    public function test_same_property_non_in_house_stay_not_404(): void
    {
        // Create a stay with ROOM_ASSIGNED status (not IN_HOUSE)
        [$reservation, , $room] = $this->assignReadyReservation('8201');
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign(
            $this->frontDeskActor, $reservation, $room, null, 'assign-nonih-' . Str::ulid()
        );

        $stay = $assigned['stay']->fresh();
        $this->assertSame('ROOM_ASSIGNED', $stay->status->value);

        // Query the boundary for this same-property non-IN_HOUSE stay
        $b = $this->service()->boundary($this->frontDeskActor, $stay->id);

        // Must not 404 — same property stay must be found
        $this->assertSame($stay->id, $b['front_desk_stay_id']);
        $this->assertSame('ROOM_ASSIGNED', $b['stay_status']);
        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_STAY_NOT_IN_HOUSE, $b['blocker_codes']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertFalse($b['authoritative_gates']['stay_in_house']['satisfied']);
        $this->assertStringContainsString('ROOM_ASSIGNED', $b['authoritative_gates']['stay_in_house']['detail']);
    }

    public function test_unknown_stay_id_returns_404(): void
    {
        $nonExistentStayId = (string) Str::ulid();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk stay not found.');

        $this->service()->boundary($this->frontDeskActor, $nonExistentStayId);
    }

    public function test_cross_property_stay_not_disclosed(): void
    {
        // Create real FK-satisfying data in other property
        $otherGuestId = $this->guest($this->otherProperty, 'Cross-Property Guest');
        $otherReservationId = $this->reservation($this->otherProperty, $otherGuestId, 'RES-XP-' . strtoupper(Str::random(5)), 'confirmed');

        $stayId = (string) Str::ulid();
        \Illuminate\Support\Facades\DB::table('front_desk_stays')->insert([
            'id' => $stayId,
            'property_id' => $this->otherProperty->id,
            'reservation_id' => $otherReservationId,
            'guest_id' => $otherGuestId,
            'status' => 'IN_HOUSE',
            'created_by' => $this->frontDeskActor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query from the main property context — the stay belongs to otherProperty, so 404
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk stay not found.');

        $this->service()->boundary($this->frontDeskActor, $stayId);
    }

    // ── Boundary Prerequisite Tests ──

    public function test_no_b7_evidence_cannot_execute(): void
    {
        $s = $this->checkedInStay('8203');

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_EVIDENCE_MISSING, $b['blocker_codes']);
        $this->assertSame($s[0]->id, $b['front_desk_stay_id']);
        $this->assertNull($b['latest_final_review_status']);
        $this->assertEmpty($b['review_reasons']);
    }

    public function test_b7_blocked_returns_execution_boundary_blocked(): void
    {
        $s = $this->checkedInStay('8204');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_BLOCKED', 'B7 blocked.', 'dcfr-' . Str::ulid());

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertSame('CHECKOUT_FINAL_REVIEW_BLOCKED', $b['latest_final_review_status']);
        $this->assertEmpty($b['review_reasons']);
    }

    public function test_b7_reviewed_returns_execution_boundary_review_required(): void
    {
        $s = $this->checkedInStay('8205');
        $this->seedB3B4B5B6Ready($s);
        app(FrontDeskDepartureCheckoutFinalReviewService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_FINAL_REVIEW_REVIEWED', 'B7 reviewed.', 'dcfr-' . Str::ulid());

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_REVIEW_REQUIRED', $b['execution_boundary_status']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertNotEmpty($b['review_reasons']);
        $this->assertStringContainsString('REVIEWED', $b['review_reasons'][0]);
    }

    public function test_b7_ready_does_not_imply_can_execute(): void
    {
        $s = $this->checkedInStay('8206');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        // B7 is READY, but financial/cashier/business-date gates are unavailable → still blocked
        $this->assertFalse($b['can_execute']);
        $this->assertSame('CHECKOUT_FINAL_REVIEW_READY', $b['latest_final_review_status']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_NOT_READY, $b['blocker_codes']);
        $this->assertNotContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FD_B7_EVIDENCE_MISSING, $b['blocker_codes']);
        // Still blocked by unavailable gates
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $b['execution_boundary_status']);
    }

    // ── Financial / Source Availability Tests ──

    public function test_financial_settlement_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8207');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_FINANCIAL_SETTLEMENT_UNAVAILABLE, $b['blocker_codes']);

        $financialGate = $b['authoritative_gates']['financial_settlement'] ?? null;
        $this->assertNotNull($financialGate);
        $this->assertFalse($financialGate['satisfied']);
        $this->assertSame('PMS Guest Ledger', $financialGate['owner']);
    }

    public function test_cashier_obligation_evidence_unavailable(): void
    {
        $s = $this->checkedInStay('8208');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertContains(FrontDeskDepartureCheckoutExecutionBoundaryProjectionService::BLOCKER_CASHIER_OBLIGATION_UNAVAILABLE, $b['blocker_codes']);
    }

    public function test_no_fabricated_ready_result(): void
    {
        $s = $this->checkedInStay('8209');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertFalse($b['can_execute']);
        $this->assertNotSame('EXECUTION_BOUNDARY_READY', $b['execution_boundary_status']);
    }

    // ── Authorization and Isolation ──

    public function test_unauthorized_actor_rejected(): void
    {
        $s = $this->checkedInStay('8210');
        $this->seedB3B4B5B6B7Ready($s);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Front Desk checkout execution boundary view permission is required.');

        $this->service()->boundary($this->financeActor, $s[0]->id);
    }

    public function test_projection_uses_current_property_context(): void
    {
        $s = $this->checkedInStay('8211');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($this->property->id, $b['property_id']);
    }

    // ── Read-Only Boundary ──

    public function test_projection_does_not_mutate_stay_status(): void
    {
        $s = $this->checkedInStay('8212');
        $this->seedB3B4B5B6B7Ready($s);

        $stayBefore = $s[0]->fresh()->status->value;

        $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $stayAfter = $s[0]->fresh()->status->value;

        $this->assertSame($stayBefore, $stayAfter);
        $this->assertSame('IN_HOUSE', $stayAfter);
    }

    public function test_projection_does_not_mutate_b7_records(): void
    {
        $s = $this->checkedInStay('8213');
        $this->seedB3B4B5B6B7Ready($s);

        $b7CountBefore = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('front_desk_stay_id', $s[0]->id)->count();

        $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $b7CountAfter = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('front_desk_stay_id', $s[0]->id)->count();

        $this->assertSame($b7CountBefore, $b7CountAfter);
    }

    public function test_repeated_get_requests_are_stable(): void
    {
        $s = $this->checkedInStay('8214');
        $this->seedB3B4B5B6B7Ready($s);

        $b1 = $this->service()->boundary($this->frontDeskActor, $s[0]->id);
        $b2 = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame($b1['execution_boundary_status'], $b2['execution_boundary_status']);
        $this->assertSame($b1['can_execute'], $b2['can_execute']);
        $this->assertSame($b1['blocker_codes'], $b2['blocker_codes']);
        $this->assertSame($b1['latest_final_review_status'], $b2['latest_final_review_status']);
    }

    // ── Execution Marker ──

    public function test_execution_not_performed_marker_present(): void
    {
        $s = $this->checkedInStay('8215');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('Checkout execution is not performed in FD-B8.', $b['execution_not_performed_marker']);
    }

    // ── All Authoritative Gates Present ──

    public function test_all_eight_authoritative_gates_present(): void
    {
        $s = $this->checkedInStay('8216');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

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

    // ── Queue Integration ──

    public function test_queue_includes_boundary_summary(): void
    {
        $s = $this->checkedInStay('8217');
        $this->seedB3B4B5B6B7Ready($s);

        $queue = $this->queueService()->queue($this->frontDeskActor);

        // Find the projected stay across all queue views (it's overdue because departure_date is before test date)
        $projected = null;
        foreach (array_merge(
            $queue['views']['dueOutToday'],
            $queue['views']['dueOutTomorrow'],
            $queue['views']['dueOutFuture'],
            $queue['views']['overdueDepartures']
        ) as $row) {
            if ($row['stay_id'] === $s[0]->id) {
                $projected = $row;
                break;
            }
        }
        $this->assertNotNull($projected, 'Stay should appear in departure queue.');

        $this->assertTrue($projected['can_view_execution_boundary']);

        $boundary = $projected['departure_checkout_execution_boundary'];
        $this->assertNotNull($boundary);
        $this->assertFalse($boundary['can_execute']);
        $this->assertSame('EXECUTION_BOUNDARY_BLOCKED', $boundary['execution_boundary_status']);
        $this->assertNotEmpty($boundary['blocker_codes']);
        $this->assertIsArray($boundary['review_reasons']);
        $this->assertSame('Checkout execution is not performed in FD-B8.', $boundary['execution_not_performed_marker']);
    }

    public function test_queue_does_not_silently_normalize_boundary_exception(): void
    {
        // The queue resolves stays from the same property with permission checked
        // Normal resolution must succeed for an IN_HOUSE stay with permission
        $s = $this->checkedInStay('8218');
        $this->seedB3B4B5B6B7Ready($s);

        $queue = $this->queueService()->queue($this->frontDeskActor);

        // Find the stay and verify boundary is not null (not silently swallowed)
        $found = false;
        foreach (array_merge(
            $queue['views']['dueOutToday'],
            $queue['views']['dueOutTomorrow'],
            $queue['views']['dueOutFuture'],
            $queue['views']['overdueDepartures']
        ) as $row) {
            if ($row['stay_id'] === $s[0]->id) {
                $found = true;
                $this->assertNotNull($row['departure_checkout_execution_boundary'], 'Boundary should not be null for permitted actor.');
                break;
            }
        }
        $this->assertTrue($found, 'Stay not found in queue views.');
    }

    // ── Route Boundary ──

    public function test_boundary_route_exists_as_get_only(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $getFound = false;
        $writeFound = false;

        foreach ($routes as $route) {
            $uri = $route->uri();

            // Check for any checkout-execution-boundary route
            if (str_contains($uri, 'checkout-execution-boundary')) {
                $methods = $route->methods();
                if (in_array('GET', $methods)) {
                    $getFound = true;
                }
                if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
                    $writeFound = true;
                }
            }

            // Check for any checkout-execution route that could be a write
            if (preg_match('/checkout-execut(?:e|ion)\b/', $uri)) {
                $methods = $route->methods();
                if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
                    $writeFound = true;
                }
            }
        }

        $this->assertTrue($getFound, 'GET route for checkout-execution-boundary must exist.');
        $this->assertFalse($writeFound, 'No POST/PUT/PATCH/DELETE checkout execution route may exist.');
    }

    public function test_no_checkout_execution_write_route_exists(): void
    {
        $allRoutes = collect(Route::getRoutes()->getRoutes());

        $forbiddenWriteRoutes = [];
        $forbiddenWriteRouteNames = [];

        foreach ($allRoutes as $route) {
            $uri = $route->uri();
            $name = $route->getName() ?? '';
            $methods = $route->methods();

            // Allow the read-only boundary index route by name
            if ($name === 'frontdesk.stays.departure-checkout-execution-boundary.index') {
                continue;
            }

            // Collect any checkout-execution URI with a write method
            if (str_contains($uri, 'checkout-execut')) {
                if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
                    $forbiddenWriteRoutes[] = implode(',', $methods) . ' ' . $uri;
                }
            }

            // Collect any checkout-execution write route name (store/create/execute/update/destroy)
            foreach (['store', 'create', 'execute', 'update', 'destroy'] as $action) {
                if (str_contains($name, 'checkout-execution.' . $action)) {
                    $forbiddenWriteRouteNames[] = $name;
                }
            }
        }

        $this->assertSame([], $forbiddenWriteRoutes, 'No POST/PUT/PATCH/DELETE checkout execution route may exist.');
        $this->assertSame([], $forbiddenWriteRouteNames, 'No checkout execution write route name may exist.');
    }

    // ── Workspace Boundary ──

    public function test_boundary_marks_execution_not_performed(): void
    {
        $s = $this->checkedInStay('8219');
        $this->seedB3B4B5B6B7Ready($s);

        $b = $this->service()->boundary($this->frontDeskActor, $s[0]->id);

        $this->assertSame('Checkout execution is not performed in FD-B8.', $b['execution_not_performed_marker']);
        $this->assertStringContainsString('Not evaluated', $b['financial_settlement_marker']);
        $this->assertStringContainsString('B8', $b['financial_settlement_marker']);
    }

    public function test_workspace_source_contract(): void
    {
        $workspacePath = base_path('resources/js/Pages/Ivorq/FrontDesk/FrontDeskWorkspace.tsx');
        $this->assertFileExists($workspacePath, 'FrontDeskWorkspace.tsx must exist.');

        $source = file_get_contents($workspacePath);
        $this->assertNotEmpty($source);

        // 1. Type contract: CheckoutExecutionBoundarySummary must define all required fields
        $this->assertStringContainsString('execution_boundary_status', $source, 'Type must include execution_boundary_status.');
        $this->assertStringContainsString('can_execute', $source, 'Type must include can_execute.');
        $this->assertStringContainsString('blocker_codes', $source, 'Type must include blocker_codes.');
        $this->assertStringContainsString('blocker_messages', $source, 'Type must include blocker_messages.');
        $this->assertStringContainsString('review_reasons', $source, 'Type must include review_reasons.');
        $this->assertStringContainsString('execution_not_performed_marker', $source, 'Type must include execution_not_performed_marker.');

        // 2. Semantic badge mappings
        $this->assertStringContainsString("'success'", $source, 'READY must map to success badge status.');
        $this->assertStringContainsString("'warning'", $source, 'BLOCKED must map to warning badge status.');
        $this->assertStringContainsString("'pending'", $source, 'REVIEW_REQUIRED must map to pending badge status.');

        // 3. Required marker strings
        $this->assertStringContainsString('Checkout execution not yet available', $source, 'Disabled affordance marker must exist.');
        $this->assertStringContainsString('Checkout execution is not performed in FD-B8.', $source, 'Not-performed marker must exist.');
        $this->assertStringContainsString('Financial settlement: Not evaluated', $source, 'Financial exclusion marker must exist.');
        $this->assertStringContainsString('Front Desk Package B8', $source, 'Package B8 marker must exist.');

        // 4. No enabled checkout execution action — the panel must not contain a checkout button/form
        $panelStart = strpos($source, 'function CheckoutExecutionBoundaryPanel');
        $this->assertNotFalse($panelStart, 'Boundary panel function must exist.');
        $panelSource = substr($source, $panelStart);

        // No POST form targeting checkout execution within the boundary panel
        $this->assertStringNotContainsString('method="post"', strtolower($panelSource), 'No POST form within checkout execution boundary panel.');

        // No enabled Checkout button label within the panel (only the disabled affordance)
        // The panel contains "Checkout execution not yet available" but must not contain "Check Out" as a button
        $this->assertStringNotContainsString('>Check Out<', $panelSource, 'No Check Out button may exist in boundary panel.');

        // 5. Verify the panel renders review_reasons and blocker_messages
        $this->assertStringContainsString('review_reasons', $panelSource, 'Panel must reference review_reasons.');
        $this->assertStringContainsString('blocker_messages', $panelSource, 'Panel must reference blocker_messages.');
    }
}

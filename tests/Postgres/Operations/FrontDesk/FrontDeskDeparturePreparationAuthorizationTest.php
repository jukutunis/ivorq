<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationAuthorizationTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Permission required ──

    public function test_permission_required_for_departure_view(): void
    {
        try {
            app(FrontDeskDepartureQueueProjectionService::class)->queue($this->financeActor);
            $this->fail('Finance actor without departure-preparation.view should be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_front_desk_actor_with_permission_can_view(): void
    {
        [$stay] = $this->checkedInStay('1901');

        $queue = app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskActor);
        $this->assertIsArray($queue);
        $this->assertArrayHasKey('snapshots', $queue);
        $this->assertGreaterThanOrEqual(1, $queue['snapshots']['dueOutTomorrow']);
    }

    // ── Unauthenticated denied ──

    public function test_unauthenticated_denied(): void
    {
        $this->get('/frontdesk/departures')->assertRedirect('/login');
    }

    public function test_unauthenticated_json_denied(): void
    {
        $this->getJson('/frontdesk/departures')->assertUnauthorized();
    }

    // ── Finance roles do not receive this permission by default ──

    public function test_finance_actor_without_permission_is_denied(): void
    {
        try {
            app(FrontDeskDepartureQueueProjectionService::class)->queue($this->financeActor);
            $this->fail('Finance actor must not have departure preparation view permission by default.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    // ── Engineering actor without permission is denied ──

    public function test_engineering_actor_without_permission_is_denied(): void
    {
        try {
            app(FrontDeskDepartureQueueProjectionService::class)->queue($this->engineeringActor);
            $this->fail('Engineering actor must not access Front Desk departure workspace without explicit permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    // ── View-only actor without permission is denied ──

    public function test_front_desk_view_only_actor_without_departure_permission_is_denied(): void
    {
        try {
            app(FrontDeskDepartureQueueProjectionService::class)->queue($this->frontDeskViewOnlyActor);
            $this->fail('Front Desk view-only actor without departure preparation permission must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    // ── No write permissions exist in FD-B1 ──

    public function test_no_departure_execution_permission_exists(): void
    {
        $this->assertFalse(
            $this->frontDeskActor->can('frontdesk.departure-preparation.execute'),
            'No departure execution permission must exist in FD-B1.'
        );
        $this->assertFalse(
            $this->frontDeskActor->can('frontdesk.checkout.execute'),
            'No checkout execution permission must exist in FD-B1.'
        );
    }

    // ── Helpers ──

    protected function checkedInStay(string $roomNumber): array
    {
        [$reservation, , $room] = $this->assignReadyReservation($roomNumber);
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid());
        $context = 'check-in-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $assigned['stay']->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        $stay = app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $assigned['stay']->id, $context);

        return [$stay->fresh(), $room, $reservation];
    }
}

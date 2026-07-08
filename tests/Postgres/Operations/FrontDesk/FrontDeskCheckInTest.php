<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskCheckInTest extends PostgresTestCase
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

    public function test_unauthenticated_permission_and_confirmation_are_required(): void
    {
        [$stay] = $this->assignedStay('1001');

        $this->postJson("/frontdesk/stays/{$stay->id}/check-in", [
            'idempotency_context' => 'unauth-' . Str::ulid(),
        ])->assertUnauthorized();

        try {
            app(FrontDeskCheckInService::class)->checkIn($this->frontDeskViewOnlyActor, $stay->id, 'noperm-' . Str::ulid());
            $this->fail('Check-in must require the exact Front Desk check-in permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->expectException(DomainException::class);
        app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $stay->id, 'missing-confirmation-' . Str::ulid());
    }

    public function test_successful_check_in_uses_bound_confirmation_and_has_no_financial_side_effects(): void
    {
        [$stay] = $this->assignedStay('1002');
        $before = $this->domainTableCounts();
        $context = 'check-in-' . Str::ulid();

        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $stay->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm(
            $this->frontDeskActor,
            FrontDeskCheckInService::INTENT,
            'password',
            $this->property->company_id,
            $this->property->id,
            $hash
        );

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/check-in", [
                'property_id' => $this->otherProperty->id,
                'guest_id' => (string) Str::ulid(),
                'status' => 'CHECKED_OUT',
                'checked_in_at' => '2020-01-01T00:00:00Z',
                'checked_in_by' => $this->financeActor->id,
                'idempotency_context' => $context,
            ]);

        $response->assertOk()->assertJson(['status' => 'IN_HOUSE']);

        $fresh = FrontDeskStay::withoutGlobalScopes()->findOrFail($stay->id);
        $assignment = FrontDeskRoomAssignment::withoutGlobalScopes()->findOrFail($fresh->current_room_assignment_id);
        $this->assertSame('IN_HOUSE', $fresh->status->value);
        $this->assertSame($this->frontDeskActor->id, $fresh->checked_in_by);
        $this->assertSame('2026-07-08T09:00:00.000000Z', $fresh->checked_in_at->toISOString());
        $this->assertSame($fresh->current_room_id, $assignment->room_id);
        $this->assertSame(1, DB::table('front_desk_room_assignments')->count());

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/check-in", [
                'idempotency_context' => $context,
            ])->assertUnprocessable();
    }

    public function test_check_in_revalidates_changed_sources_and_cross_property_context(): void
    {
        [$hkStay, , $hkRoom] = $this->assignedStay('1003');
        $context = 'hk-change-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $hkStay->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        DB::table('rooms')->where('id', $hkRoom)->update(['readiness_state' => 'waiting_inspection']);
        $this->expectCheckInFailure($hkStay->id, $context);

        [$engStay, , $engRoom] = $this->assignedStay('1004');
        $context = 'eng-change-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $engStay->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        $this->activeEngineeringBlock($engRoom);
        $this->expectCheckInFailure($engStay->id, $context);

        [$reservationStay] = $this->assignedStay('1005');
        $context = 'res-change-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $reservationStay->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        DB::table('reservations')->where('id', $reservationStay->reservation_id)->update(['status' => 'cancelled']);
        $this->expectCheckInFailure($reservationStay->id, $context);

        [$crossStay] = $this->assignedStay('1006');
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        session($this->propertySession($this->otherProperty));
        $this->expectException(DomainException::class);
        app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $crossStay->id, 'cross-' . Str::ulid());
    }

    public function test_confirmation_context_binds_assignment_and_guest_identity(): void
    {
        [$stay] = $this->assignedStay('1007');
        $context = 'assignment-change-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $stay->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);

        DB::table('front_desk_stays')->where('id', $stay->id)->update(['guest_id' => $this->guest($this->property, 'Changed Guest')]);

        $this->expectCheckInFailure($stay->id, $context);
    }

    private function assignedStay(string $roomNumber): array
    {
        [$reservation, , $room] = $this->assignReadyReservation($roomNumber);
        $result = app(FrontDeskRoomAssignmentService::class)->assign(
            $this->frontDeskActor,
            $reservation,
            $room,
            null,
            'assign-' . Str::ulid()
        );

        return [$result['stay']->fresh(), $result['assignment']->fresh(), $room];
    }

    private function expectCheckInFailure(string $stayId, string $context): void
    {
        try {
            app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $stayId, $context);
            $this->fail('Check-in should fail closed.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}

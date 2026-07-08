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
use Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskRoomMoveTest extends PostgresTestCase
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

    public function test_room_move_auth_permission_and_confirmation_boundary(): void
    {
        [$stay, , $target] = $this->checkedInStayWithTarget('1401', '1402');

        $this->postJson("/frontdesk/stays/{$stay->id}/room-move", [
            'target_room_id' => $target,
            'move_reason' => 'Move guest away from elevator.',
            'idempotency_key' => 'unauth-' . Str::ulid(),
            'idempotency_context' => 'unauth-' . Str::ulid(),
        ])->assertUnauthorized();

        try {
            app(FrontDeskRoomMoveService::class)->move($this->frontDeskViewOnlyActor, $stay->id, $target, 'No permission', 'noperm-' . Str::ulid(), 'noperm-' . Str::ulid());
            $this->fail('Room move must require exact permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->expectException(DomainException::class);
        app(FrontDeskRoomMoveService::class)->move($this->frontDeskActor, $stay->id, $target, 'Missing confirmation', 'missing-' . Str::ulid(), 'missing-' . Str::ulid());
    }

    public function test_successful_room_move_is_immutable_in_house_and_non_mutating(): void
    {
        [$stay, $sourceRoom, $targetRoom] = $this->checkedInStayWithTarget('1403', '1404');
        $before = $this->domainTableCounts();
        $reason = 'Guest requested quieter room.';
        $context = 'move-' . Str::ulid();
        $idempotency = 'move-key-' . Str::ulid();

        $this->confirmMove($stay, $targetRoom, $reason, $context);
        $result = app(FrontDeskRoomMoveService::class)->move($this->frontDeskActor, $stay->id, $targetRoom, $reason, $idempotency, $context);

        $fresh = FrontDeskStay::withoutGlobalScopes()->findOrFail($stay->id);
        $this->assertSame('IN_HOUSE', $fresh->status->value);
        $this->assertSame($targetRoom, $fresh->current_room_id);
        $this->assertSame($result['assignment']->id, $fresh->current_room_assignment_id);
        $this->assertSame('ROOM_MOVE', $result['assignment']->assignment_kind->value);
        $this->assertSame($reason, $result['assignment']->assignment_reason);
        $this->assertSame($this->frontDeskActor->id, $result['assignment']->created_by);
        $this->assertSame('2026-07-08T09:00:00.000000Z', $result['assignment']->occurred_at->toISOString());
        $this->assertSame(1, DB::table('front_desk_room_assignments')->where('assignment_kind', 'INITIAL_ASSIGNMENT')->where('room_id', $sourceRoom)->count());
        $this->assertSame(1, DB::table('front_desk_room_assignments')->where('assignment_kind', 'ROOM_MOVE')->where('room_id', $targetRoom)->count());

        $replay = app(FrontDeskRoomMoveService::class)->move($this->frontDeskActor, $stay->id, $targetRoom, $reason, $idempotency, $context);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(2, DB::table('front_desk_room_assignments')->count());

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);

        $this->expectException(DomainException::class);
        $result['assignment']->assignment_reason = 'mutate';
        $result['assignment']->save();
    }

    public function test_room_move_source_blockers_fail_closed(): void
    {
        [$stay, , $targetRoom] = $this->checkedInStayWithTarget('1405', '1406');
        $notReady = $this->room($this->property, '1407', ['readiness_state' => 'waiting_inspection']);
        $blocked = $this->room($this->property, '1408');
        $this->activeEngineeringBlock($blocked);
        $occupied = $this->checkedInStayWithTarget('1409', '1410')[1];
        $mismatch = $this->room($this->property, '1411', ['room_type' => 'standard']);
        $otherPropertyRoom = $this->room($this->otherProperty, '2411');

        $this->expectMoveFailure($stay, $stay->current_room_id, 'same room');
        $this->expectMoveFailure($stay, $otherPropertyRoom, 'cross property');
        $this->expectMoveFailure($stay, $mismatch, 'type mismatch');
        $this->expectMoveFailure($stay, $notReady, 'housekeeping');
        $this->expectMoveFailure($stay, $blocked, 'engineering');
        $this->expectMoveFailure($stay, $occupied, 'occupied');

        DB::table('front_desk_stays')->where('id', $stay->id)->update(['status' => 'ROOM_ASSIGNED']);
        $this->expectMoveFailure($stay->fresh(), $targetRoom, 'not in house');
    }

    public function test_room_move_confirmation_binds_current_assignment_source_target_and_readiness(): void
    {
        [$stay, , $targetRoom] = $this->checkedInStayWithTarget('1412', '1413');
        $reason = 'Operational relocation.';
        $context = 'bind-' . Str::ulid();
        $this->confirmMove($stay, $targetRoom, $reason, $context);

        DB::table('rooms')->where('id', $targetRoom)->update(['readiness_state' => 'waiting_engineering']);
        $this->expectMoveFailure($stay->fresh(), $targetRoom, $reason, $context);

        [$stay2, , $target2] = $this->checkedInStayWithTarget('1414', '1415');
        $context2 = 'bind-' . Str::ulid();
        $this->confirmMove($stay2, $target2, $reason, $context2);
        $this->activeEngineeringBlock($target2);
        $this->expectMoveFailure($stay2->fresh(), $target2, $reason, $context2);

        [$stay3, , $target3] = $this->checkedInStayWithTarget('1416', '1417');
        $context3 = 'bind-' . Str::ulid();
        $this->confirmMove($stay3, $target3, $reason, $context3);
        DB::table('front_desk_stays')->where('id', $stay3->id)->update(['current_room_assignment_id' => FrontDeskRoomAssignment::withoutGlobalScopes()->where('front_desk_stay_id', $stay3->id)->value('id')]);
        $this->expectMoveFailure($stay3->fresh(), $this->room($this->property, '1418'), $reason, $context3);
    }

    protected function checkedInStayWithTarget(string $sourceRoomNumber, string $targetRoomNumber): array
    {
        [$reservation, , $sourceRoom] = $this->assignReadyReservation($sourceRoomNumber);
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign($this->frontDeskActor, $reservation, $sourceRoom, null, 'assign-' . Str::ulid());
        $context = 'check-in-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation($this->frontDeskActor, $assigned['stay']->id, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskCheckInService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
        $stay = app(FrontDeskCheckInService::class)->checkIn($this->frontDeskActor, $assigned['stay']->id, $context);
        $targetRoom = $this->room($this->property, $targetRoomNumber);

        return [$stay->fresh(), $sourceRoom, $targetRoom];
    }

    private function confirmMove(FrontDeskStay $stay, string $targetRoom, string $reason, string $context): void
    {
        $hash = app(FrontDeskRoomMoveService::class)->prepareConfirmation($this->frontDeskActor, $stay->id, $targetRoom, $reason, $context);
        app(SensitiveActionConfirmationService::class)->confirm($this->frontDeskActor, FrontDeskRoomMoveService::INTENT, 'password', $this->property->company_id, $this->property->id, $hash);
    }

    private function expectMoveFailure(FrontDeskStay $stay, string $targetRoom, string $reason, ?string $context = null): void
    {
        try {
            app(FrontDeskRoomMoveService::class)->move($this->frontDeskActor, $stay->id, $targetRoom, $reason, 'fail-' . Str::ulid(), $context ?? 'fail-' . Str::ulid());
            $this->fail('Room move should fail closed.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}

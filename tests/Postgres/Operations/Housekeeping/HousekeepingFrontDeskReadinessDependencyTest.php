<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\HousekeepingReadinessDependencyService;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PostgresTestCase;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;

class HousekeepingFrontDeskReadinessDependencyTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingRoomReadinessData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-08 12:00:00');
        $this->setUpHousekeepingRoomReadinessFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_front_desk_can_read_projection_via_dependency_service(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '601');

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $result['readiness_status']);
        $this->assertEquals($this->property->id, $result['property_id']);
        $this->assertEquals($roomId, $result['room_id']);
    }

    public function test_front_desk_cannot_read_projection_without_permission(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '602');

        try {
            app(HousekeepingReadinessDependencyService::class)
                ->roomReadiness($this->engineeringActor, $roomId);
            $this->fail('Front desk HK readiness view permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_front_desk_cannot_mutate_room_readiness(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '603');

        try {
            app(HousekeepingRoomReadinessTransitionService::class)->startCleaning(
                $this->frontDeskActor, $roomId, 'idem-fd-cant-603',
            );
            $this->fail('Front Desk must not be able to transition readiness.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_dirty_room_is_blocked_for_front_desk(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '604');

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
        $this->assertEquals('waiting_cleaning', $result['source_status']);
    }

    public function test_clean_room_is_blocked_for_front_desk(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '605');

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
        $this->assertEquals('waiting_inspection', $result['source_status']);
    }

    public function test_inspected_room_is_ready_for_front_desk(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '606');

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $result['readiness_status']);
    }

    public function test_cross_property_room_is_unknown_for_front_desk(): void
    {
        $roomId = $this->hkInspectedRoom($this->otherProperty, '607');

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::UNKNOWN, $result['readiness_status']);
    }

    public function test_blocked_room_is_blocked_for_front_desk(): void
    {
        $roomId = $this->hkRoom($this->property, '608', [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'blocked',
        ]);

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
    }

    public function test_room_readiness_projection_is_read_only_for_front_desk(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '609');
        $before = Room::find($roomId)->toArray();

        app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $after = Room::find($roomId)->toArray();

        $this->assertEquals($before['readiness_state'], $after['readiness_state']);
        $this->assertEquals($before['cleanliness_status'], $after['cleanliness_status']);
    }

    public function test_unknown_source_is_blocked_for_front_desk(): void
    {
        $fakeRoomId = '01JVX0000000000000000999';

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $fakeRoomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::UNKNOWN, $result['readiness_status']);
    }

    public function test_cleaning_state_is_blocked_for_front_desk(): void
    {
        $roomId = $this->hkRoom($this->property, '610', [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'cleaning',
        ]);

        $result = app(HousekeepingReadinessDependencyService::class)
            ->roomReadiness($this->frontDeskActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
    }
}

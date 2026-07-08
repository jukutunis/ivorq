<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Tests\PostgresTestCase;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;

class HousekeepingRoomReadinessProjectionTest extends PostgresTestCase
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

    public function test_ready_room_projects_housekeeping_ready(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '101');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $result['readiness_status']);
        $this->assertEquals($this->property->id, $result['property_id']);
        $this->assertEquals($roomId, $result['room_id']);
        $this->assertEquals('ready_for_sale', $result['source_status']);
        $this->assertNull($result['blocking_reason']);
    }

    public function test_dirty_room_projects_housekeeping_blocked(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '102');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
        $this->assertEquals('waiting_cleaning', $result['source_status']);
        $this->assertNotNull($result['blocking_reason']);
    }

    public function test_clean_room_projects_housekeeping_blocked(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '103');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
        $this->assertEquals('waiting_inspection', $result['source_status']);
    }

    public function test_unknown_source_projects_housekeeping_unknown(): void
    {
        $fakeRoomId = '01JVX0000000000000000000';

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $fakeRoomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::UNKNOWN, $result['readiness_status']);
        $this->assertEquals('unknown', $result['source_status']);
    }

    public function test_cross_property_room_projects_unknown(): void
    {
        $roomId = $this->hkInspectedRoom($this->otherProperty, '201');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::UNKNOWN, $result['readiness_status']);
    }

    public function test_cross_tenant_room_projects_unknown(): void
    {
        $roomId = $this->hkInspectedRoom($this->otherTenantProperty, '301');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::UNKNOWN, $result['readiness_status']);
    }

    public function test_inactive_room_projects_unknown(): void
    {
        $roomId = $this->hkRoom($this->property, '104', ['is_active' => false]);

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::UNKNOWN, $result['readiness_status']);
    }

    public function test_blocked_room_projects_housekeeping_blocked(): void
    {
        $roomId = $this->hkRoom($this->property, '105', [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'blocked',
        ]);

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
        $this->assertEquals('blocked', $result['source_status']);
    }

    public function test_ready_for_arrival_projects_housekeeping_ready(): void
    {
        $roomId = $this->hkRoom($this->property, '106', [
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_arrival',
        ]);

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $result['readiness_status']);
    }

    public function test_ready_for_vip_projects_housekeeping_ready(): void
    {
        $roomId = $this->hkRoom($this->property, '107', [
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_vip',
            'is_vip' => true,
        ]);

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $result['readiness_status']);
    }

    public function test_cleaning_state_projects_housekeeping_blocked(): void
    {
        $roomId = $this->hkRoom($this->property, '108', [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'cleaning',
        ]);

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $result['readiness_status']);
    }

    public function test_evaluated_at_is_server_owned(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '109');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);

        $this->assertArrayHasKey('evaluated_at', $result);
        $this->assertNotNull($result['evaluated_at']);
    }
}

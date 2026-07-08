<?php

namespace Tests\Postgres\Operations\Engineering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Tests\Postgres\Operations\Engineering\Concerns\CreatesEngineeringRoomAvailabilityData;
use Tests\PostgresTestCase;

class EngineeringRoomAvailabilityProjectionTest extends PostgresTestCase
{
    use CreatesEngineeringRoomAvailabilityData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));
        $this->setUpEngineeringRoomAvailabilityFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_projection_returns_available_blocked_and_unknown_statuses(): void
    {
        $service = app(EngineeringRoomAvailabilityProjectionService::class);
        $blockService = app(EngineeringRoomAvailabilityBlockService::class);

        $availableRoom = $this->room($this->property, '1201');
        $blockedRoom = $this->room($this->property, '1202');
        $crossPropertyRoom = $this->room($this->otherProperty, '2202');

        $available = $service->forEngineering($this->engineeringActor, $availableRoom);
        $this->assertSame(EngineeringRoomAvailabilityProjectionService::AVAILABLE, $available['availability_status']);
        $this->assertSame($this->property->id, $available['property_id']);
        $this->assertSame($availableRoom, $available['room_id']);
        $this->assertNull($available['blocking_source_type']);

        $block = $blockService->block(
            $this->engineeringActor,
            $blockedRoom,
            'Air-conditioning compressor failure',
            null,
            null,
            'proj-block-' . $blockedRoom
        );

        $blocked = $service->forEngineering($this->engineeringActor, $blockedRoom);
        $this->assertSame(EngineeringRoomAvailabilityProjectionService::BLOCKED, $blocked['availability_status']);
        $this->assertSame('Air-conditioning compressor failure', $blocked['blocking_reason']);
        $this->assertSame($block->started_at->toISOString(), $blocked['blocking_started_at']);

        $unknown = $service->forEngineering($this->engineeringActor, $crossPropertyRoom);
        $this->assertSame(EngineeringRoomAvailabilityProjectionService::UNKNOWN, $unknown['availability_status']);
        $this->assertSame($this->property->id, $unknown['property_id']);
    }
}

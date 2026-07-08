<?php

namespace Tests\Postgres\Operations\Engineering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Tests\Postgres\Operations\Engineering\Concerns\CreatesEngineeringRoomAvailabilityData;
use Tests\PostgresTestCase;

class EngineeringRoomAvailabilityFrontDeskReadBoundaryTest extends PostgresTestCase
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

    public function test_front_desk_read_dependency_is_read_only_and_assignment_neutral(): void
    {
        $room = $this->room($this->property, '1501');
        $guest = $this->guest($this->property);
        $this->reservation($this->property, $guest, $room);

        app(EngineeringRoomAvailabilityBlockService::class)->block(
            $this->engineeringActor,
            $room,
            'Smoke detector replacement',
            null,
            null,
            'fd-read-' . Str::ulid()
        );

        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get("/frontdesk/engineering-availability/{$room}?availability_status=ENGINEERING_AVAILABLE&property_id=fake&released_by=fake")
            ->assertOk()
            ->assertJsonPath('engineering_availability.property_id', $this->property->id)
            ->assertJsonPath('engineering_availability.room_id', $room)
            ->assertJsonPath('engineering_availability.availability_status', EngineeringRoomAvailabilityProjectionService::BLOCKED);

        $this->assertSame($before, $this->domainTableCounts());

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->post('/frontdesk/engineering-availability/' . $room, ['availability_status' => EngineeringRoomAvailabilityProjectionService::AVAILABLE])
            ->assertMethodNotAllowed();
    }
}

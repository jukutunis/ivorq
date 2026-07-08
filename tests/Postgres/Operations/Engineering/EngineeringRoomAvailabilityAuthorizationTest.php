<?php

namespace Tests\Postgres\Operations\Engineering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\Engineering\Concerns\CreatesEngineeringRoomAvailabilityData;
use Tests\PostgresTestCase;

class EngineeringRoomAvailabilityAuthorizationTest extends PostgresTestCase
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

    public function test_http_boundaries_require_authentication_and_exact_permissions(): void
    {
        $room = $this->room($this->property, '1301');
        $viewer = $this->user('Engineering Viewer', 'engineering-viewer@example.test');
        $this->attachProperty($viewer, $this->property);

        $this->get("/operations/engineering-room-availability/{$room}")
            ->assertRedirect();

        try {
            app(EngineeringRoomAvailabilityProjectionService::class)->forEngineering($viewer, $room);
            $this->fail('Engineering view permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $viewer->givePermissionTo(EngineeringRoomAvailabilityProjectionService::ENGINEERING_VIEW_PERMISSION);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($viewer, 'web')
            ->get("/operations/engineering-room-availability/{$room}")
            ->assertOk()
            ->assertJsonPath('engineering_availability.availability_status', EngineeringRoomAvailabilityProjectionService::AVAILABLE);

        $payload = [
            'room_id' => $room,
            'block_reason' => 'Electrical panel isolation',
            'idempotency_key' => 'auth-' . Str::ulid(),
        ];

        foreach ([$viewer, $this->financeActor] as $actor) {
            try {
                app(EngineeringRoomAvailabilityBlockService::class)->block(
                    $actor,
                    $room,
                    $payload['block_reason'],
                    null,
                    null,
                    'denied-' . Str::ulid()
                );
                $this->fail('Engineering block permission must be required.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_front_desk_can_read_projection_but_cannot_block_or_release(): void
    {
        $room = $this->room($this->property, '1302');
        $block = app(EngineeringRoomAvailabilityBlockService::class)->block(
            $this->engineeringActor,
            $room,
            'Ceiling leak',
            null,
            null,
            'frontdesk-boundary-' . $room
        );

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->get("/frontdesk/engineering-availability/{$room}")
            ->assertOk()
            ->assertJsonPath('engineering_availability.availability_status', EngineeringRoomAvailabilityProjectionService::BLOCKED);

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->post('/operations/engineering-room-availability/blocks', [
                'room_id' => $room,
                'block_reason' => 'Browser attempt',
                'idempotency_key' => 'fd-block-' . Str::ulid(),
            ])
            ->assertForbidden();

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->post("/operations/engineering-room-availability/blocks/{$block->id}/release", [
                'release_reason' => 'Browser attempt',
                'idempotency_context' => 'fd-release-' . Str::ulid(),
            ])
            ->assertForbidden();
    }
}

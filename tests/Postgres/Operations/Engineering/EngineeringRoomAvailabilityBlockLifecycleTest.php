<?php

namespace Tests\Postgres\Operations\Engineering;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Engineering\Enums\EngineeringRoomAvailabilityBlockStatusEnum;
use Modules\Operations\Engineering\Models\EngineeringRoomAvailabilityBlock;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\Engineering\Concerns\CreatesEngineeringRoomAvailabilityData;
use Tests\PostgresTestCase;

class EngineeringRoomAvailabilityBlockLifecycleTest extends PostgresTestCase
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

    public function test_block_action_is_server_resolved_and_does_not_mutate_external_domains(): void
    {
        $room = $this->room($this->property, '1401');
        $otherRoom = $this->room($this->otherProperty, '2401');
        $guest = $this->guest($this->property);
        $this->reservation($this->property, $guest, $room);

        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->engineeringActor, 'web')
            ->post('/operations/engineering-room-availability/blocks', [
                'property_id' => $this->otherProperty->id,
                'room_id' => $room,
                'block_status' => 'RELEASED',
                'availability_status' => EngineeringRoomAvailabilityProjectionService::AVAILABLE,
                'block_reason' => 'Bathroom ceiling opened for repair',
                'started_at' => '2020-01-01T00:00:00Z',
                'started_by' => $this->frontDeskActor->id,
                'released_at' => '2020-01-01T01:00:00Z',
                'released_by' => $this->frontDeskActor->id,
                'idempotency_key' => 'browser-control-' . Str::ulid(),
            ])
            ->assertSessionHasErrors([
                'property_id',
                'block_status',
                'availability_status',
                'started_at',
                'started_by',
                'released_at',
                'released_by',
            ]);

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->engineeringActor, 'web')
            ->post('/operations/engineering-room-availability/blocks', [
                'room_id' => $room,
                'block_reason' => 'Bathroom ceiling opened for repair',
                'idempotency_key' => 'valid-control-' . Str::ulid(),
            ]);

        $response->assertCreated();

        $block = EngineeringRoomAvailabilityBlock::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->property->id, $block->property_id);
        $this->assertSame($room, $block->room_id);
        $this->assertSame(EngineeringRoomAvailabilityBlockStatusEnum::Active, $block->block_status);
        $this->assertSame($this->engineeringActor->id, $block->started_by);
        $this->assertSame('2026-07-08T09:00:00.000000Z', $block->started_at->toISOString());
        $this->assertNull($block->released_at);

        $after = $this->domainTableCounts();
        $this->assertSame(($before['engineering_room_availability_blocks'] ?? 0) + 1, $after['engineering_room_availability_blocks']);
        unset($before['engineering_room_availability_blocks'], $after['engineering_room_availability_blocks']);
        $this->assertSame($before, $after);

        $this->expectException(DomainException::class);
        app(EngineeringRoomAvailabilityBlockService::class)->block(
            $this->engineeringActor,
            $otherRoom,
            'Cross property attempt',
            null,
            null,
            'cross-property-' . Str::ulid()
        );
    }

    public function test_duplicate_active_block_and_source_reference_fail_closed(): void
    {
        $room = $this->room($this->property, '1402');
        $service = app(EngineeringRoomAvailabilityBlockService::class);

        $first = $service->block(
            $this->engineeringActor,
            $room,
            'Main drain repair',
            null,
            null,
            'duplicate-room-' . Str::ulid()
        );

        $same = $service->block(
            $this->engineeringActor,
            $room,
            'Main drain repair',
            null,
            null,
            $first->idempotency_key
        );
        $this->assertSame($first->id, $same->id);

        try {
            $service->block(
                $this->engineeringActor,
                $room,
                'Different active block attempt',
                null,
                null,
                'duplicate-room-' . Str::ulid()
            );
            $this->fail('Duplicate active block must fail closed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('active Engineering availability block', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('engineering_room_availability_blocks')
            ->where('property_id', $this->property->id)
            ->where('room_id', $room)
            ->where('block_status', 'ACTIVE')
            ->count());

        try {
            $service->block(
                $this->engineeringActor,
                $this->room($this->property, '1403'),
                'Unproven work-order source',
                'ENGINEERING_WORK_ORDER',
                (string) Str::ulid(),
                'source-mismatch-' . Str::ulid()
            );
            $this->fail('Unsupported source reference must fail closed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }
    }

    public function test_release_requires_bound_confirmation_and_replay_fails_closed(): void
    {
        $room = $this->room($this->property, '1404');
        $service = app(EngineeringRoomAvailabilityBlockService::class);
        $block = $service->block(
            $this->engineeringActor,
            $room,
            'Wall repair',
            null,
            null,
            'release-block-' . Str::ulid()
        );

        $releaseReason = 'Engineering verified the room is technically clear.';
        $idempotencyContext = 'release-' . Str::ulid();

        try {
            $service->release($this->engineeringActor, $block->id, $releaseReason, $idempotencyContext);
            $this->fail('Release without confirmation must fail closed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('confirmation evidence', $exception->getMessage());
        }

        $hash = $service->releaseEvidenceHash($block->fresh(), $releaseReason, $idempotencyContext);
        app(SensitiveActionConfirmationService::class)->confirm(
            $this->engineeringActor,
            EngineeringRoomAvailabilityBlockService::RELEASE_INTENT,
            'password',
            $this->property->company_id,
            $this->property->id,
            $hash
        );

        $released = $service->release($this->engineeringActor, $block->id, $releaseReason, $idempotencyContext);
        $this->assertSame(EngineeringRoomAvailabilityBlockStatusEnum::Released, $released->block_status);
        $this->assertSame($this->engineeringActor->id, $released->released_by);
        $this->assertSame('2026-07-08T09:00:00.000000Z', $released->released_at->toISOString());

        $projection = app(EngineeringRoomAvailabilityProjectionService::class)
            ->forEngineering($this->engineeringActor, $room);
        $this->assertSame(EngineeringRoomAvailabilityProjectionService::AVAILABLE, $projection['availability_status']);

        $this->expectException(DomainException::class);
        $service->release($this->engineeringActor, $block->id, $releaseReason, $idempotencyContext);
    }

    public function test_cross_property_release_fails_closed(): void
    {
        $room = $this->room($this->property, '1405');
        $block = app(EngineeringRoomAvailabilityBlockService::class)->block(
            $this->engineeringActor,
            $room,
            'Door hardware replacement',
            null,
            null,
            'cross-release-' . Str::ulid()
        );

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        session($this->propertySession($this->otherProperty));

        $this->expectException(DomainException::class);
        app(EngineeringRoomAvailabilityBlockService::class)->release(
            $this->engineeringActor,
            $block->id,
            'Wrong property release',
            'wrong-property-release-' . Str::ulid()
        );
    }
}

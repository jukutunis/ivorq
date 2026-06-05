<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Services\RoomBlockService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class RoomBlockModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    private function boot(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_room_block_persists_with_active_status(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);

        $block = $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(2),
        ]);

        $this->assertInstanceOf(RoomBlock::class, $block);
        $this->assertSame(RoomBlockStatusEnum::Active, $block->status);
        $this->assertDatabaseHas('room_blocks', [
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'status'      => 'active',
        ]);
    }

    public function test_create_room_block_fires_event_and_logs_activity(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);

        $block = $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(2),
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => RoomBlock::class,
            'subject_id'   => $block->id,
        ]);
    }

    // ── Overlap detection ─────────────────────────────────────────────────────

    public function test_create_rejects_overlapping_block_for_same_room(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);

        $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(3),
        ]);

        $this->expectException(ValidationException::class);

        $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfService->value,
            'start_at'    => now()->addDays(2),
            'end_at'      => now()->addDays(4),
        ]);
    }

    public function test_create_allows_non_overlapping_blocks_for_same_room(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);

        $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDay(),
        ]);

        // Non-overlapping — starts after the first ends
        $second = $service->create([
            'property_id' => $property->id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'    => now()->addDays(2),
            'end_at'      => now()->addDays(4),
        ]);

        $this->assertInstanceOf(RoomBlock::class, $second);
    }

    public function test_create_allows_overlapping_blocks_for_different_rooms(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $roomA   = $this->makePmsRoom($property);
        $roomB   = $this->makePmsRoom($property);

        $service->create([
            'property_id' => $property->id,
            'room_id'     => $roomA->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(3),
        ]);

        $blockB = $service->create([
            'property_id' => $property->id,
            'room_id'     => $roomB->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'    => now()->addHour(),
            'end_at'      => now()->addDays(3),
        ]);

        $this->assertInstanceOf(RoomBlock::class, $blockB);
    }

    // ── Release ───────────────────────────────────────────────────────────────

    public function test_release_sets_status_released_and_records_timestamps(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);
        $block   = $this->makePmsRoomBlock($room);

        $released = $service->release($block->id);

        $this->assertSame(RoomBlockStatusEnum::Released, $released->status);
        $this->assertNotNull($released->released_at);
        $this->assertSame($admin->id, $released->released_by);
    }

    public function test_release_fires_room_block_released_and_logs_activity(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);
        $block   = $this->makePmsRoomBlock($room);

        $service->release($block->id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => RoomBlock::class,
            'subject_id'   => $block->id,
        ]);
    }

    public function test_release_fails_from_already_released(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);
        $block   = $this->makePmsRoomBlock($room, ['status' => RoomBlockStatusEnum::Released->value]);

        $this->expectException(ValidationException::class);
        $service->release($block->id);
    }

    // ── Expire ────────────────────────────────────────────────────────────────

    public function test_expire_sets_status_to_expired(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);
        $block   = $this->makePmsRoomBlock($room, [
            'start_at' => now()->subDays(2),
            'end_at'   => now()->subHour(),
        ]);

        $expired = $service->expire($block->id);

        $this->assertSame(RoomBlockStatusEnum::Expired, $expired->status);
    }

    public function test_expire_fails_from_terminal_released_status(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);
        $block   = $this->makePmsRoomBlock($room, ['status' => RoomBlockStatusEnum::Released->value]);

        $this->expectException(ValidationException::class);
        $service->expire($block->id);
    }

    public function test_expire_overdue_batch_expires_past_active_blocks(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RoomBlockService::class);
        $room    = $this->makePmsRoom($property);

        // Overdue block
        $overdue = $this->makePmsRoomBlock($room, [
            'start_at' => now()->subDays(3),
            'end_at'   => now()->subHour(),
        ]);

        // Future block — must NOT be expired
        $future = $this->makePmsRoomBlock($room, [
            'start_at' => now()->addDays(5),
            'end_at'   => now()->addDays(7),
        ]);

        $count = $service->expireOverdue();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertSame(RoomBlockStatusEnum::Expired, $overdue->fresh()->status);
        $this->assertSame(RoomBlockStatusEnum::Active,  $future->fresh()->status);
    }

    // ── Policy & cross-property ────────────────────────────────────────────────

    public function test_cross_property_policy_denies_block_management(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RB-PB-X']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room  = $this->makePmsRoom($propertyA);
        $block = $this->makePmsRoomBlock($room);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view',         $block)->denied());
        $this->assertTrue(Gate::inspect('update',       $block)->denied());
        $this->assertTrue(Gate::inspect('delete',       $block)->denied());
        $this->assertTrue(Gate::inspect('changeStatus', $block)->denied());
    }

    public function test_staff_cannot_create_room_blocks(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', RoomBlock::class)->denied());
    }
}

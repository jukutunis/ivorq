<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Events\RoomBlockCreated;
use Modules\Operations\PMS\Events\RoomBlockReleased;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Repositories\RoomBlockRepository;

class RoomBlockService
{
    public function __construct(
        private RoomBlockRepository $roomBlockRepository,
        private PmsPropertyAuthority $authority,
        private PmsServiceInput $input,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $this->authority->require('pms.room-block.view');

        return $this->roomBlockRepository->paginate($filters, $perPage);
    }

    public function find(string $id): RoomBlock
    {
        $this->authority->require('pms.room-block.view');

        return $this->roomBlockRepository->find($id);
    }

    public function create(array $data): RoomBlock
    {
        [, $propertyId] = $this->authority->require('pms.room-block.create');
        abort_if(array_intersect(array_keys($data), ['property_id', 'status', 'released_at', 'released_by']) !== [], 422);
        $block = DB::transaction(function () use ($data, $propertyId): RoomBlock {
            $this->lockProperty($propertyId);
            abort_unless(is_string($data['room_id'] ?? null), 422);
            $this->assertRoom($propertyId, $data['room_id']);
            $data = $this->input->roomBlock($data, true);
            $this->assertNoOverlap($data['room_id'], $data['start_at'], $data['end_at'] ?? null);

            return $this->roomBlockRepository->create($data + [
                'property_id' => $propertyId, 'status' => RoomBlockStatusEnum::Active->value,
            ]);
        });

        event(new RoomBlockCreated($block));

        return $block;
    }

    public function update(string $id, array $data): RoomBlock
    {
        [, $propertyId] = $this->authority->require('pms.room-block.edit');
        abort_if(array_intersect(array_keys($data), ['property_id', 'status', 'released_at', 'released_by']) !== [], 422);
        return DB::transaction(function () use ($id, $data, $propertyId): RoomBlock {
            $this->lockProperty($propertyId);
            $existing = $this->locked($id, $propertyId);
            if (array_key_exists('room_id', $data)) {
                abort_unless(is_string($data['room_id']), 422);
                $this->assertRoom($propertyId, $data['room_id']);
            }
            $data = $this->input->roomBlock($data, false,
                $existing->start_at?->toDateTimeString(), $existing->end_at?->toDateTimeString());
            $merged = [
                'room_id' => $data['room_id'] ?? $existing->room_id,
                'block_type' => $data['block_type'] ?? $existing->block_type->value,
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $existing->reason?->value,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $existing->notes,
                'start_at' => $data['start_at'] ?? $existing->start_at?->toDateTimeString(),
                'end_at' => array_key_exists('end_at', $data) ? $data['end_at'] : $existing->end_at?->toDateTimeString(),
            ];
            $this->input->roomBlock($merged, true);
            $this->assertRoom($propertyId, $merged['room_id']);
            if ($existing->status === RoomBlockStatusEnum::Active) {
                $this->assertNoOverlap($merged['room_id'], $merged['start_at'], $merged['end_at'], $id);
            }
            $existing->update($data);

            return $existing->fresh();
        });
    }

    public function release(string $id): RoomBlock
    {
        [$actor, $propertyId] = $this->authority->require('pms.room-block.edit');
        $block = DB::transaction(function () use ($id, $propertyId, $actor): RoomBlock {
            $this->lockProperty($propertyId);
            $block = $this->locked($id, $propertyId);
            $this->assertTransition($block, RoomBlockStatusEnum::Released);
            $block->update([
                'status' => RoomBlockStatusEnum::Released,
                'released_at' => now(),
                'released_by' => $actor->id,
            ]);

            return $block->fresh();
        });

        event(new RoomBlockReleased($block));

        return $block;
    }

    public function expire(string $id): RoomBlock
    {
        [, $propertyId] = $this->authority->require('pms.room-block.edit');

        return DB::transaction(function () use ($id, $propertyId): RoomBlock {
            $this->lockProperty($propertyId);
            $block = $this->locked($id, $propertyId);
            $this->assertTransition($block, RoomBlockStatusEnum::Expired);
            $block->update(['status' => RoomBlockStatusEnum::Expired]);

            return $block->fresh();
        });
    }

    public function hasOverlap(
        string $roomId,
        string $startAt,
        ?string $endAt,
        ?string $excludeId = null
    ): bool {
        $this->authority->require('pms.room-block.view');

        return $this->roomBlockRepository->hasOverlap($roomId, $startAt, $endAt, $excludeId);
    }

    public function expireOverdue(): int
    {
        [, $propertyId] = $this->authority->require('pms.room-block.edit');

        return DB::transaction(function () use ($propertyId): int {
            $this->lockProperty($propertyId);

            return $this->roomBlockRepository->expireOverdue();
        });
    }

    private function lockProperty(string $propertyId): void
    {
        abort_unless(DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first(), 403);
    }

    private function locked(string $id, string $propertyId): RoomBlock
    {
        return RoomBlock::query()->where('property_id', $propertyId)->lockForUpdate()->findOrFail($id);
    }

    private function assertRoom(string $propertyId, string $roomId): void
    {
        abort_unless(Room::query()->where('property_id', $propertyId)->whereKey($roomId)->exists(), 422);
    }

    private function assertNoOverlap(string $roomId, string $startAt, ?string $endAt, ?string $excludeId = null): void
    {
        if ($this->roomBlockRepository->hasOverlap($roomId, $startAt, $endAt, $excludeId)) {
            throw ValidationException::withMessages([
                'room_id' => ['An active block already exists for this room during the requested period.'],
            ]);
        }
    }

    private function assertTransition(RoomBlock $block, RoomBlockStatusEnum $target): void
    {
        if (! $block->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition a room block in {$block->status->label()} status."],
            ]);
        }
    }
}

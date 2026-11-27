<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        abort_unless(Room::query()->where('property_id', $propertyId)
            ->whereKey($data['room_id'] ?? null)->exists(), 422);
        $data = $this->input->roomBlock($data, true);
        $data['property_id'] = $propertyId;
        $data['status'] = RoomBlockStatusEnum::Active->value;
        if ($this->roomBlockRepository->hasOverlap($data['room_id'], $data['start_at'], $data['end_at'] ?? null)) {
            throw ValidationException::withMessages([
                'room_id' => ['An active block already exists for this room during the requested period.'],
            ]);
        }

        $block = $this->roomBlockRepository->create($data);

        event(new RoomBlockCreated($block));

        return $block;
    }

    public function update(string $id, array $data): RoomBlock
    {
        [, $propertyId] = $this->authority->require('pms.room-block.edit');
        abort_if(array_intersect(array_keys($data), ['property_id', 'status', 'released_at', 'released_by']) !== [], 422);
        $existing = $this->roomBlockRepository->findOrFail($id);
        abort_unless($existing->property_id === $propertyId, 404);
        if (isset($data['room_id'])) {
            abort_unless(Room::query()->where('property_id', $propertyId)
                ->whereKey($data['room_id'])->exists(), 422);
        }
        $data = $this->input->roomBlock($data, false,
            $existing->start_at?->format('Y-m-d H:i:s'), $existing->end_at?->format('Y-m-d H:i:s'));

        $roomId = $data['room_id'] ?? $existing->room_id;
        $startAt = $data['start_at'] ?? $existing->start_at?->toDateTimeString();
        $endAt = array_key_exists('end_at', $data)
            ? $data['end_at']
            : $existing->end_at?->toDateTimeString();

        if ($this->roomBlockRepository->hasOverlap($roomId, $startAt, $endAt, $id)) {
            throw ValidationException::withMessages([
                'room_id' => ['An active block already exists for this room during the requested period.'],
            ]);
        }

        return $this->roomBlockRepository->update($id, $data);
    }

    public function release(string $id): RoomBlock
    {
        [, $propertyId] = $this->authority->require('pms.room-block.edit');
        $block = $this->roomBlockRepository->findOrFail($id);
        abort_unless($block->property_id === $propertyId, 404);

        if (! $block->status->canTransitionTo(RoomBlockStatusEnum::Released)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot release a room block in {$block->status->label()} status."],
            ]);
        }

        $block->update([
            'status' => RoomBlockStatusEnum::Released,
            'released_at' => now(),
            'released_by' => auth()->id(),
        ]);

        $block = $block->fresh();

        event(new RoomBlockReleased($block));

        return $block;
    }

    public function expire(string $id): RoomBlock
    {
        [, $propertyId] = $this->authority->require('pms.room-block.edit');
        $block = $this->roomBlockRepository->findOrFail($id);
        abort_unless($block->property_id === $propertyId, 404);

        if (! $block->status->canTransitionTo(RoomBlockStatusEnum::Expired)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot expire a room block in {$block->status->label()} status."],
            ]);
        }

        $block->update(['status' => RoomBlockStatusEnum::Expired]);

        return $block->fresh();
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
        $this->authority->require('pms.room-block.edit');

        return $this->roomBlockRepository->expireOverdue();
    }
}

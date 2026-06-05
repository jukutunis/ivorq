<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Models\RoomBlock;
use Shared\Exceptions\NotFoundException;

class RoomBlockRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = RoomBlock::with(['room', 'releasedBy'])->latest('start_at');

        if (! empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (! empty($filters['block_type'])) {
            $query->where('block_type', $filters['block_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): RoomBlock
    {
        $block = RoomBlock::with(['room', 'releasedBy'])->find($id);

        throw_if(! $block, new NotFoundException('RoomBlock'));

        return $block;
    }

    public function findOrFail(string $id): RoomBlock
    {
        return RoomBlock::findOrFail($id);
    }

    public function create(array $data): RoomBlock
    {
        return RoomBlock::create($data)->fresh();
    }

    public function update(string $id, array $data): RoomBlock
    {
        $block = $this->find($id);
        $block->update($data);

        return $block->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    // ── Specialised queries ──────────────────────────────────────────────────

    /**
     * All active blocks for a specific room.
     */
    public function activeForRoom(string $roomId): Collection
    {
        return RoomBlock::where('room_id', $roomId)
            ->where('status', RoomBlockStatusEnum::Active)
            ->where(function ($q) {
                $q->whereNull('end_at')
                  ->orWhere('end_at', '>', now());
            })
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Check whether a proposed block period overlaps with any existing active block
     * for the same room.
     *
     * Overlap condition: existing.start_at < proposed.end_at
     *                AND existing.end_at   > proposed.start_at
     *                (null end_at means indefinite — always overlaps future periods)
     */
    public function hasOverlap(
        string  $roomId,
        string  $startAt,
        ?string $endAt,
        ?string $excludeId = null
    ): bool {
        $query = RoomBlock::where('room_id', $roomId)
            ->where('status', RoomBlockStatusEnum::Active)
            ->where('start_at', '<', $endAt ?? '9999-12-31 23:59:59')
            ->where(function ($q) use ($startAt) {
                $q->whereNull('end_at')
                  ->orWhere('end_at', '>', $startAt);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function expireOverdue(): int
    {
        return RoomBlock::where('status', RoomBlockStatusEnum::Active)
            ->whereNotNull('end_at')
            ->where('end_at', '<=', now())
            ->update(['status' => RoomBlockStatusEnum::Expired]);
    }
}

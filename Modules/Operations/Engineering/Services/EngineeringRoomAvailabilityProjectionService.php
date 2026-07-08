<?php

namespace Modules\Operations\Engineering\Services;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\EngineeringRoomAvailabilityBlockStatusEnum;
use Modules\Operations\Engineering\Models\EngineeringRoomAvailabilityBlock;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EngineeringRoomAvailabilityProjectionService
{
    public const ENGINEERING_VIEW_PERMISSION = 'engineering.room-availability.view';
    public const FRONT_DESK_VIEW_PERMISSION = 'frontdesk.engineering-availability.view';

    public const AVAILABLE = 'ENGINEERING_AVAILABLE';
    public const BLOCKED = 'ENGINEERING_BLOCKED';
    public const UNKNOWN = 'ENGINEERING_UNKNOWN';

    /**
     * @return array<string, mixed>
     */
    public function forEngineering(User $actor, string $roomId): array
    {
        if (! $actor->can(self::ENGINEERING_VIEW_PERMISSION)) {
            throw new HttpException(403, 'Engineering room availability view permission is required.');
        }

        return $this->project($roomId);
    }

    /**
     * @return array<string, mixed>
     */
    public function forFrontDesk(User $actor, string $roomId): array
    {
        if (! $actor->can(self::FRONT_DESK_VIEW_PERMISSION)) {
            throw new HttpException(403, 'Front Desk Engineering availability view permission is required.');
        }

        return $this->project($roomId);
    }

    /**
     * @return array<string, mixed>
     */
    public function project(string $roomId): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        $room = Room::withoutGlobalScopes()
            ->whereKey($roomId)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->first();

        if (! $room) {
            return $this->unknown($propertyId, $roomId);
        }

        $block = EngineeringRoomAvailabilityBlock::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->where('block_status', EngineeringRoomAvailabilityBlockStatusEnum::Active->value)
            ->orderBy('started_at')
            ->first();

        if ($block) {
            return [
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'availability_status' => self::BLOCKED,
                'blocking_source_type' => $block->source_type,
                'blocking_source_id' => $block->source_id,
                'blocking_reason' => $block->block_reason,
                'blocking_started_at' => $block->started_at?->toISOString(),
                'evaluated_at' => now()->toISOString(),
            ];
        }

        return [
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'availability_status' => self::AVAILABLE,
            'blocking_source_type' => null,
            'blocking_source_id' => null,
            'blocking_reason' => null,
            'blocking_started_at' => null,
            'evaluated_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknown(string $propertyId, string $roomId): array
    {
        return [
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'availability_status' => self::UNKNOWN,
            'blocking_source_type' => null,
            'blocking_source_id' => null,
            'blocking_reason' => 'Engineering availability source is missing or outside the active property.',
            'blocking_started_at' => null,
            'evaluated_at' => now()->toISOString(),
        ];
    }
}

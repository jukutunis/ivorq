<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HousekeepingRoomReadinessProjectionService
{
    public const HOUSEKEEPING_VIEW_PERMISSION = 'housekeeping.room-readiness.view';
    public const FRONT_DESK_VIEW_PERMISSION = 'frontdesk.housekeeping-readiness.view';

    public const READY = 'HOUSEKEEPING_READY';
    public const BLOCKED = 'HOUSEKEEPING_BLOCKED';
    public const UNKNOWN = 'HOUSEKEEPING_UNKNOWN';

    private const READY_STATES = ['ready_for_sale', 'ready_for_arrival', 'ready_for_vip'];
    private const BLOCKING_STATES = ['waiting_cleaning', 'dirty', 'cleaning', 'waiting_inspection', 'blocked'];

    /**
     * @return array<string, mixed>
     */
    public function forHousekeeping(User $actor, string $roomId): array
    {
        if (! $actor->can(self::HOUSEKEEPING_VIEW_PERMISSION)) {
            throw new HttpException(403, 'Housekeeping room readiness view permission is required.');
        }

        return $this->project($roomId);
    }

    /**
     * @return array<string, mixed>
     */
    public function forFrontDesk(User $actor, string $roomId): array
    {
        if (! $actor->can(self::FRONT_DESK_VIEW_PERMISSION)) {
            throw new HttpException(403, 'Front Desk housekeeping readiness view permission is required.');
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

        $readinessState = (string) ($room->readiness_state ?? 'unknown');
        $cleanlinessStatus = $room->cleanliness_status instanceof \BackedEnum
            ? $room->cleanliness_status->value
            : (string) $room->cleanliness_status;

        if (in_array($readinessState, self::READY_STATES, true)) {
            return [
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'readiness_status' => self::READY,
                'source_status' => $readinessState,
                'cleanliness_status' => $cleanlinessStatus,
                'blocking_reason' => null,
                'evaluated_at' => now()->toISOString(),
            ];
        }

        if (in_array($readinessState, self::BLOCKING_STATES, true)) {
            return [
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'readiness_status' => self::BLOCKED,
                'source_status' => $readinessState,
                'cleanliness_status' => $cleanlinessStatus,
                'blocking_reason' => "Housekeeping readiness is \"{$readinessState}\".",
                'evaluated_at' => now()->toISOString(),
            ];
        }

        return [
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'readiness_status' => self::BLOCKED,
            'source_status' => $readinessState,
            'cleanliness_status' => $cleanlinessStatus,
            'blocking_reason' => "Housekeeping readiness state \"{$readinessState}\" is not a recognized ready state.",
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
            'readiness_status' => self::UNKNOWN,
            'source_status' => 'unknown',
            'cleanliness_status' => 'unknown',
            'blocking_reason' => 'Housekeeping readiness source is missing, inactive, or outside the active property.',
            'evaluated_at' => now()->toISOString(),
        ];
    }
}

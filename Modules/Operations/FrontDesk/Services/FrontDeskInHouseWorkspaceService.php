<?php

namespace Modules\Operations\FrontDesk\Services;

use Illuminate\Support\Facades\Schema;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskInHouseWorkspaceService
{
    public const VIEW_PERMISSION = 'frontdesk.in-house.view';

    public function __construct(private readonly EngineeringAvailabilityDependencyService $engineeringAvailability) {}

    /**
     * @return array<string, mixed>
     */
    public function workspace(User $actor): array
    {
        [$propertyId, $property] = $this->authorizeView($actor);

        $stays = FrontDeskStay::withoutGlobalScopes()
            ->with([
                'reservation' => fn ($query) => $query->withoutGlobalScopes(),
                'guest' => fn ($query) => $query->withoutGlobalScopes(),
                'currentRoom' => fn ($query) => $query->withoutGlobalScopes(),
                'currentAssignment' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('property_id', $propertyId)
            ->where('status', FrontDeskStayStatusEnum::InHouse->value)
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn (FrontDeskStay $stay) => $this->projectStay($actor, $stay, $propertyId))
            ->values();

        return [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'company_id' => $property->company_id,
            ],
            'snapshots' => [
                'inHouse' => $stays->count(),
                'roomMoveReady' => $stays->where('actions.can_move_room', true)->count(),
                'roomMoveBlocked' => $stays->where('actions.can_move_room', false)->count(),
            ],
            'views' => [
                'inHouseStays' => $stays->all(),
            ],
            'financeMarker' => 'Financial settlement: Not evaluated in Front Desk Package A.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectStay(User $actor, FrontDeskStay $stay, string $propertyId): array
    {
        $assignments = FrontDeskRoomAssignment::withoutGlobalScopes()
            ->with(['room' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stay->id)
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (FrontDeskRoomAssignment $assignment) => [
                'id' => $assignment->id,
                'assignment_kind' => $this->enumValue($assignment->assignment_kind),
                'room_id' => $assignment->room_id,
                'room_number' => $assignment->room?->room_number,
                'assignment_reason' => $assignment->assignment_reason,
                'occurred_at' => $assignment->occurred_at?->toISOString(),
                'created_by' => $assignment->created_by,
                'source_hash' => $assignment->source_hash,
            ])
            ->values();

        $targetCandidates = Room::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->where('id', '<>', $stay->current_room_id)
            ->orderBy('room_number')
            ->limit(25)
            ->get()
            ->map(fn (Room $room) => $this->projectTargetRoom($actor, $stay, $room, $propertyId))
            ->values();

        return [
            'stay_id' => $stay->id,
            'reservation' => [
                'id' => $stay->reservation_id,
                'number' => $stay->reservation?->reservation_number,
                'arrival_date' => $stay->reservation?->arrival_date?->toDateString(),
                'departure_date' => $stay->reservation?->departure_date?->toDateString(),
                'room_type' => $this->enumValue($stay->reservation?->reserved_room_type),
            ],
            'guest' => [
                'id' => $stay->guest_id,
                'name' => $stay->guest?->full_name,
                'vip_level' => $stay->guest?->vip_level,
            ],
            'status' => $this->enumValue($stay->status),
            'current_room' => [
                'id' => $stay->current_room_id,
                'number' => $stay->currentRoom?->room_number,
                'room_type' => $this->enumValue($stay->currentRoom?->room_type),
            ],
            'current_room_assignment_id' => $stay->current_room_assignment_id,
            'checked_in_at' => $stay->checked_in_at?->toISOString(),
            'checked_in_by' => $stay->checked_in_by,
            'assignment_history' => $assignments->all(),
            'target_room_candidates' => $targetCandidates->all(),
            'actions' => [
                'can_move_room' => $actor->can(FrontDeskRoomMoveService::EXECUTE_PERMISSION),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectTargetRoom(User $actor, FrontDeskStay $stay, Room $room, string $propertyId): array
    {
        $blockers = [];
        $readiness = (string) ($room->readiness_state ?? 'unknown');
        if (! in_array($readiness, ['ready_for_arrival', 'ready_for_sale', 'ready_for_vip'], true)) {
            $blockers[] = 'Housekeeping readiness blocks room move.';
        }

        $engineering = $this->engineeringAvailability->roomAvailability($actor, $room->id);
        if (($engineering['availability_status'] ?? null) !== EngineeringRoomAvailabilityProjectionService::AVAILABLE) {
            $blockers[] = 'Engineering availability blocks room move.';
        }

        if ($this->activeOccupancyExists($propertyId, $room->id, $stay->id)) {
            $blockers[] = 'Target room is occupied by another Front Desk stay.';
        }

        return [
            'id' => $room->id,
            'number' => $room->room_number,
            'room_type' => $this->enumValue($room->room_type),
            'housekeeping' => [
                'readiness_state' => $readiness,
                'cleanliness_status' => $this->enumValue($room->cleanliness_status),
            ],
            'engineering' => [
                'state' => $engineering['availability_status'] ?? EngineeringRoomAvailabilityProjectionService::UNKNOWN,
                'blocking_reason' => $engineering['blocking_reason'] ?? null,
            ],
            'eligible' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    private function activeOccupancyExists(string $propertyId, string $roomId, string $stayId): bool
    {
        if (! Schema::hasTable('front_desk_stays')) {
            return false;
        }

        return FrontDeskStay::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('current_room_id', $roomId)
            ->where('id', '<>', $stayId)
            ->whereIn('status', [
                FrontDeskStayStatusEnum::RoomAssigned->value,
                FrontDeskStayStatusEnum::CheckInConfirmationPending->value,
                FrontDeskStayStatusEnum::InHouse->value,
            ])
            ->exists();
    }

    /**
     * @return array{0: string, 1: Property}
     */
    private function authorizeView(User $actor): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session('active_company_id');

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (! $property) {
            throw new HttpException(403, 'Active property is required.');
        }

        if (! $actor->can(self::VIEW_PERMISSION)) {
            throw new HttpException(403, 'Front Desk in-house view permission is required.');
        }

        return [$propertyId, $property];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}

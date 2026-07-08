<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskCheckoutReadinessProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.checkout-readiness.view';

    public const READY = 'CHECKOUT_OPERATIONALLY_READY';
    public const BLOCKED = 'CHECKOUT_OPERATIONALLY_BLOCKED';
    public const UNKNOWN = 'CHECKOUT_READINESS_UNKNOWN';

    public function __construct(private readonly EngineeringAvailabilityDependencyService $engineeringAvailability) {}

    /**
     * @return array<string, mixed>
     */
    public function ready(User $actor, string $frontDeskStayId): array
    {
        [$propertyId, $property] = $this->authorizeView($actor);

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->with([
                'reservation' => fn ($query) => $query->withoutGlobalScopes(),
                'guest' => fn ($query) => $query->withoutGlobalScopes(),
                'currentRoom' => fn ($query) => $query->withoutGlobalScopes(),
                'currentAssignment' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('property_id', $propertyId)
            ->whereKey($frontDeskStayId)
            ->first();

        if (! $stay) {
            throw new HttpException(404, 'Front Desk stay not found in active property.');
        }

        return $this->project($actor, $stay, $propertyId, $property);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(User $actor, FrontDeskStay $stay, string $propertyId, Property $property): array
    {
        $blockers = [];

        if ($stay->status !== FrontDeskStayStatusEnum::InHouse) {
            $blockers[] = 'Front Desk stay is not IN_HOUSE.';
        }

        $currentRoom = $stay->currentRoom;
        if (! $currentRoom) {
            $blockers[] = 'Current room source identity cannot be resolved.';
        } elseif ($currentRoom->property_id !== $propertyId) {
            $blockers[] = 'Current room belongs to a different property.';
        }

        $currentAssignment = $stay->currentAssignment;
        if (! $currentAssignment) {
            $blockers[] = 'Current room assignment evidence is missing.';
        } else {
            if ($currentAssignment->property_id !== $propertyId) {
                $blockers[] = 'Current room assignment belongs to a different property.';
            }

            if ($currentAssignment->front_desk_stay_id !== $stay->id) {
                $blockers[] = 'Current room assignment does not belong to this stay.';
            }

            if ($currentRoom && $currentAssignment->room_id !== $stay->current_room_id) {
                $blockers[] = 'Current room assignment room differs from stay current_room_id.';
            }
        }

        if (! $stay->guest || ! $stay->guest->exists) {
            $blockers[] = 'Guest source identity cannot be resolved.';
        } elseif ($stay->guest->property_id !== $propertyId) {
            $blockers[] = 'Guest belongs to a different property.';
        }

        if (! $stay->reservation || ! $stay->reservation->exists) {
            $blockers[] = 'Reservation source identity cannot be resolved.';
        } elseif ($stay->reservation->property_id !== $propertyId) {
            $blockers[] = 'Reservation belongs to a different property.';
        }

        $housekeeping = $this->projectHousekeeping($stay, $currentRoom);
        if ($housekeeping['blocking']) {
            $blockers[] = $housekeeping['blocking_reason'];
        }

        $engineering = $this->projectEngineering($actor, $stay, $currentRoom);
        if ($engineering['blocking']) {
            $blockers[] = $engineering['blocking_reason'];
        }

        $assignmentHistory = $this->projectAssignmentHistory($stay, $propertyId);
        $assignmentConsistency = $this->checkAssignmentConsistency($stay, $assignmentHistory, $currentAssignment);
        if ($assignmentConsistency !== null) {
            $blockers[] = $assignmentConsistency;
        }

        $readinessStatus = $blockers === [] ? self::READY : self::BLOCKED;

        return [
            'property_id' => $propertyId,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'current_room_id' => $stay->current_room_id,
            'current_room_assignment_id' => $stay->current_room_assignment_id,
            'readiness_status' => $readinessStatus,
            'operational_blockers' => $blockers,
            'evidence' => [
                'stay' => [
                    'id' => $stay->id,
                    'status' => $this->enumValue($stay->status),
                    'checked_in_at' => $stay->checked_in_at?->toISOString(),
                    'checked_in_by' => $stay->checked_in_by,
                ],
                'reservation' => [
                    'id' => $stay->reservation_id,
                    'number' => $stay->reservation?->reservation_number,
                    'arrival_date' => $stay->reservation?->arrival_date?->toDateString(),
                    'departure_date' => $stay->reservation?->departure_date?->toDateString(),
                    'room_type' => $this->enumValue($stay->reservation?->reserved_room_type),
                    'status' => $stay->reservation?->status,
                ],
                'guest' => [
                    'id' => $stay->guest_id,
                    'name' => $stay->guest?->full_name,
                    'vip_level' => $stay->guest?->vip_level,
                ],
                'current_room' => [
                    'id' => $stay->current_room_id,
                    'number' => $currentRoom?->room_number,
                    'room_type' => $this->enumValue($currentRoom?->room_type),
                    'readiness_state' => (string) ($currentRoom?->readiness_state ?? 'unknown'),
                ],
                'current_assignment' => $currentAssignment ? [
                    'id' => $currentAssignment->id,
                    'assignment_kind' => $this->enumValue($currentAssignment->assignment_kind),
                    'room_id' => $currentAssignment->room_id,
                    'source_hash' => $currentAssignment->source_hash,
                ] : null,
                'assignment_history' => $assignmentHistory,
                'housekeeping' => $housekeeping,
                'engineering' => $engineering,
            ],
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package A.',
            'evaluated_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectHousekeeping(FrontDeskStay $stay, ?Room $currentRoom): array
    {
        if (! $currentRoom) {
            return [
                'source' => 'unknown',
                'readiness_state' => 'unknown',
                'blocking' => true,
                'blocking_reason' => 'Housekeeping dependency is ambiguous: current room is not resolvable.',
            ];
        }

        $readiness = (string) ($currentRoom->readiness_state ?? 'unknown');

        $blockingStates = ['dirty', 'waiting_cleaning', 'waiting_inspection', 'blocked', 'unknown'];
        if (in_array($readiness, $blockingStates, true)) {
            return [
                'source' => 'Housekeeping Room readiness_state',
                'readiness_state' => $readiness,
                'blocking' => true,
                'blocking_reason' => "Housekeeping readiness is \"{$readiness}\".",
            ];
        }

        return [
            'source' => 'Housekeeping Room readiness_state',
            'readiness_state' => $readiness,
            'blocking' => false,
            'blocking_reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectEngineering(User $actor, FrontDeskStay $stay, ?Room $currentRoom): array
    {
        if (! $currentRoom) {
            return [
                'source' => 'Engineering Availability Projection',
                'availability_status' => EngineeringRoomAvailabilityProjectionService::UNKNOWN,
                'blocking' => true,
                'blocking_reason' => 'Engineering dependency is ambiguous: current room is not resolvable.',
            ];
        }

        $availability = $this->engineeringAvailability->roomAvailability($actor, $currentRoom->id);
        $status = $availability['availability_status'] ?? EngineeringRoomAvailabilityProjectionService::UNKNOWN;

        if ($status === EngineeringRoomAvailabilityProjectionService::BLOCKED) {
            return [
                'source' => 'Engineering Availability Projection',
                'availability_status' => $status,
                'blocking_reason' => $availability['blocking_reason'] ?? 'Engineering block is active.',
                'blocking_source_type' => $availability['blocking_source_type'] ?? null,
                'blocking' => true,
            ];
        }

        if ($status === EngineeringRoomAvailabilityProjectionService::UNKNOWN) {
            return [
                'source' => 'Engineering Availability Projection',
                'availability_status' => $status,
                'blocking_reason' => $availability['blocking_reason'] ?? 'Engineering availability source is missing or ambiguous.',
                'blocking_source_type' => null,
                'blocking' => true,
            ];
        }

        return [
            'source' => 'Engineering Availability Projection',
            'availability_status' => $status,
            'blocking_reason' => null,
            'blocking_source_type' => null,
            'blocking' => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectAssignmentHistory(FrontDeskStay $stay, string $propertyId): array
    {
        return FrontDeskRoomAssignment::withoutGlobalScopes()
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
            ->values()
            ->all();
    }

    private function checkAssignmentConsistency(
        FrontDeskStay $stay,
        array $assignmentHistory,
        ?FrontDeskRoomAssignment $currentAssignment,
    ): ?string {
        if ($assignmentHistory === []) {
            return 'Assignment history is empty.';
        }

        if ($currentAssignment) {
            $currentInHistory = collect($assignmentHistory)->firstWhere('id', $currentAssignment->id);
            if ($currentInHistory === null) {
                return 'Current assignment is not present in assignment history.';
            }
        }

        $initialAssignments = collect($assignmentHistory)
            ->where('assignment_kind', 'INITIAL_ASSIGNMENT');

        if ($initialAssignments->count() !== 1) {
            return 'Assignment history must contain exactly one INITIAL_ASSIGNMENT.';
        }

        $roomMoves = collect($assignmentHistory)->where('assignment_kind', 'ROOM_MOVE');
        $lastRecordedRoom = $initialAssignments->first()['room_id'] ?? null;

        foreach ($roomMoves as $move) {
            if ($move['room_id'] === $lastRecordedRoom) {
                return 'Room move evidence is inconsistent: consecutive assignments reference the same room.';
            }
            $lastRecordedRoom = $move['room_id'];
        }

        if ($stay->current_room_id !== $lastRecordedRoom) {
            return 'Assignment history final room does not match stay current_room_id.';
        }

        return null;
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
            throw new HttpException(403, 'Front Desk checkout readiness view permission is required.');
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

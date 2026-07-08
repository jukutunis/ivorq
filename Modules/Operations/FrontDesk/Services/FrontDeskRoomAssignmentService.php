<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Enums\FrontDeskRoomAssignmentKindEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskRoomAssignmentService
{
    public const CREATE_PERMISSION = 'frontdesk.room-assignment.create';

    private const READY_HOUSEKEEPING_STATES = [
        'ready_for_arrival',
        'ready_for_sale',
        'ready_for_vip',
    ];

    public function __construct(
        private readonly ArrivalEligibilityProjectionService $arrivalEligibility,
        private readonly EngineeringAvailabilityDependencyService $engineeringAvailability,
    ) {}

    /**
     * @return array{stay: FrontDeskStay, assignment: FrontDeskRoomAssignment, replayed: bool}
     */
    public function assign(
        User $actor,
        string $reservationId,
        string $roomId,
        ?string $assignmentReason,
        string $idempotencyKey
    ): array {
        if (! $actor->can(self::CREATE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk room assignment permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $reservationId, $roomId, $assignmentReason, $idempotencyKey) {
            $reservation = $this->lockReservation($propertyId, $reservationId);
            $room = $this->lockRoom($propertyId, $roomId);

            $existingIdempotent = FrontDeskRoomAssignment::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingIdempotent) {
                $stay = FrontDeskStay::withoutGlobalScopes()
                    ->whereKey($existingIdempotent->front_desk_stay_id)
                    ->where('property_id', $propertyId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $existingIdempotent->reservation_id === $reservation->id
                    && $existingIdempotent->guest_id === $reservation->primary_guest_id
                    && $existingIdempotent->room_id === $room->id
                ) {
                    return [
                        'stay' => $stay->fresh(),
                        'assignment' => $existingIdempotent->fresh(),
                        'replayed' => true,
                    ];
                }

                throw new DomainException('Idempotency key was already used for a different Front Desk room assignment outcome.');
            }

            $eligibility = $this->arrivalEligibility->reservation($actor, $reservation->id);
            if (($eligibility['eligibility']['eligible'] ?? false) !== true) {
                throw new DomainException('Reservation is not arrival-eligible by the Front Desk arrival projection.');
            }

            $this->assertCanonicalGuest($reservation, $propertyId);
            $this->assertRoomTypeCompatibility($reservation, $room);
            $housekeeping = $this->assertHousekeepingReady($room);
            $engineering = $this->assertEngineeringAvailable($actor, $room->id);

            $stay = FrontDeskStay::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('reservation_id', $reservation->id)
                ->lockForUpdate()
                ->first();

            if (! $stay) {
                $stay = FrontDeskStay::create([
                    'property_id' => $propertyId,
                    'reservation_id' => $reservation->id,
                    'guest_id' => $reservation->primary_guest_id,
                    'status' => FrontDeskStayStatusEnum::ArrivalReady,
                    'created_by' => $actor->id,
                ]);

                $stay = FrontDeskStay::withoutGlobalScopes()
                    ->whereKey($stay->id)
                    ->where('property_id', $propertyId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $this->assertStayCanReceiveInitialAssignment($stay, $reservation);
            $this->assertRoomOccupancyAvailable($propertyId, $room->id, $stay->id);

            $existingInitial = FrontDeskRoomAssignment::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('front_desk_stay_id', $stay->id)
                ->where('assignment_kind', FrontDeskRoomAssignmentKindEnum::InitialAssignment->value)
                ->lockForUpdate()
                ->first();

            if ($existingInitial) {
                throw new DomainException('Initial Front Desk room assignment already exists for this stay.');
            }

            $sourceHash = $this->assignmentSourceHash($reservation, $room, $housekeeping, $engineering, $idempotencyKey);

            $assignment = FrontDeskRoomAssignment::create([
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $reservation->id,
                'guest_id' => $reservation->primary_guest_id,
                'room_id' => $room->id,
                'room_type_id' => null,
                'assignment_kind' => FrontDeskRoomAssignmentKindEnum::InitialAssignment,
                'assignment_reason' => $assignmentReason,
                'occurred_at' => now(),
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            $stay->update([
                'status' => FrontDeskStayStatusEnum::RoomAssigned,
                'current_room_id' => $room->id,
                'current_room_assignment_id' => $assignment->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit('frontdesk_room_assignment_created', $actor, $assignment, [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $reservation->id,
                'guest_id' => $reservation->primary_guest_id,
                'room_id' => $room->id,
                'assignment_kind' => FrontDeskRoomAssignmentKindEnum::InitialAssignment->value,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return [
                'stay' => $stay->fresh(),
                'assignment' => $assignment->fresh(),
                'replayed' => false,
            ];
        });
    }

    public function assignmentSourceHash(Reservation $reservation, Room $room, array $housekeeping, array $engineering, string $idempotencyKey): string
    {
        $payload = [
            'property_id' => $reservation->property_id,
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->primary_guest_id,
            'reservation_status' => $this->enumValue($reservation->status),
            'arrival_date' => $reservation->arrival_date?->toDateString(),
            'departure_date' => $reservation->departure_date?->toDateString(),
            'reserved_room_type' => $this->enumValue($reservation->reserved_room_type),
            'room_id' => $room->id,
            'room_type' => $this->enumValue($room->room_type),
            'housekeeping_readiness_state' => $housekeeping['readiness_state'] ?? null,
            'housekeeping_cleanliness_status' => $housekeeping['cleanliness_status'] ?? null,
            'engineering_availability_status' => $engineering['availability_status'] ?? null,
            'engineering_blocking_source_type' => $engineering['blocking_source_type'] ?? null,
            'engineering_blocking_source_id' => $engineering['blocking_source_id'] ?? null,
            'engineering_blocking_reason' => $engineering['blocking_reason'] ?? null,
            'assignment_kind' => FrontDeskRoomAssignmentKindEnum::InitialAssignment->value,
            'idempotency_key' => $idempotencyKey,
        ];

        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function activePropertyId(): string
    {
        return app(CurrentPropertyService::class)->resolveOrFail();
    }

    public function assertActiveProperty(string $propertyId): void
    {
        $query = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('is_active', true);

        $companyId = session('active_company_id');
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if (! $query->exists()) {
            throw new HttpException(403, 'Active property is required.');
        }
    }

    public function lockReservation(string $propertyId, string $reservationId): Reservation
    {
        $reservation = Reservation::withoutGlobalScopes()
            ->whereKey($reservationId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        if (! $reservation) {
            throw new DomainException('Reservation is missing or outside the active property.');
        }

        return $reservation;
    }

    public function lockRoom(string $propertyId, string $roomId): Room
    {
        $room = Room::withoutGlobalScopes()
            ->whereKey($roomId)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $room) {
            throw new DomainException('Room is missing, inactive, or outside the active property.');
        }

        return $room;
    }

    public function assertCanonicalGuest(Reservation $reservation, string $propertyId): void
    {
        $guest = $reservation->primaryGuest()->withoutGlobalScopes()->first();

        if (! $guest || $guest->property_id !== $propertyId || $guest->id !== $reservation->primary_guest_id) {
            throw new DomainException('Canonical primary guest linkage is missing or outside the active property.');
        }
    }

    public function assertReservationStillEligible(Reservation $reservation): void
    {
        $status = $this->enumValue($reservation->status);

        if ($status !== ReservationStatusEnum::Confirmed->value) {
            throw new DomainException('Reservation status is no longer arrival-eligible.');
        }
    }

    public function assertRoomTypeCompatibility(Reservation $reservation, Room $room): void
    {
        $reservedRoomType = $this->enumValue($reservation->reserved_room_type);
        $roomType = $this->enumValue($room->room_type);

        if ($reservedRoomType !== null && $roomType !== null && $reservedRoomType !== $roomType) {
            throw new DomainException('Room type does not match the reservation room-type requirement.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function assertHousekeepingReady(Room $room): array
    {
        $readiness = (string) ($room->readiness_state ?? 'unknown');
        $cleanliness = $this->enumValue($room->cleanliness_status);

        if (! in_array($readiness, self::READY_HOUSEKEEPING_STATES, true)) {
            throw new DomainException('Housekeeping readiness blocks Front Desk room assignment or check-in.');
        }

        return [
            'readiness_state' => $readiness,
            'cleanliness_status' => $cleanliness,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assertEngineeringAvailable(User $actor, string $roomId): array
    {
        $projection = $this->engineeringAvailability->roomAvailability($actor, $roomId);

        if (($projection['availability_status'] ?? null) !== EngineeringRoomAvailabilityProjectionService::AVAILABLE) {
            throw new DomainException('Engineering availability blocks Front Desk room assignment or check-in.');
        }

        return $projection;
    }

    public function assertRoomOccupancyAvailable(string $propertyId, string $roomId, string $currentStayId): void
    {
        $occupied = FrontDeskStay::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('current_room_id', $roomId)
            ->whereIn('status', [
                FrontDeskStayStatusEnum::RoomAssigned->value,
                FrontDeskStayStatusEnum::CheckInConfirmationPending->value,
                FrontDeskStayStatusEnum::InHouse->value,
            ])
            ->where('id', '<>', $currentStayId)
            ->lockForUpdate()
            ->exists();

        if ($occupied) {
            throw new DomainException('Room is already assigned to another active Front Desk stay.');
        }
    }

    private function assertStayCanReceiveInitialAssignment(FrontDeskStay $stay, Reservation $reservation): void
    {
        if ($stay->property_id !== $reservation->property_id || $stay->reservation_id !== $reservation->id || $stay->guest_id !== $reservation->primary_guest_id) {
            throw new DomainException('Front Desk stay source identity does not match the reservation.');
        }

        $status = $this->enumValue($stay->status);
        if ($status === FrontDeskStayStatusEnum::InHouse->value) {
            throw new DomainException('Existing Front Desk stay is already in-house.');
        }

        if ($stay->current_room_assignment_id !== null || $status !== FrontDeskStayStatusEnum::ArrivalReady->value) {
            throw new DomainException('Front Desk stay already has an initial room assignment.');
        }
    }

    private function audit(string $event, User $actor, FrontDeskRoomAssignment $assignment, array $newValues): void
    {
        AuditLog::record([
            'property_id' => $assignment->property_id,
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => FrontDeskRoomAssignment::class,
            'auditable_id' => $assignment->id,
            'old_values' => [],
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'tags' => ['frontdesk-room-assignment', $assignment->property_id, $assignment->room_id],
        ]);
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

<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskRoomAssignmentKindEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Models\Reservation;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskRoomMoveService
{
    public const EXECUTE_PERMISSION = 'frontdesk.room-move.execute';
    public const INTENT = 'frontdesk-room-move';

    public function __construct(
        private readonly FrontDeskRoomAssignmentService $assignmentService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function prepareConfirmation(
        User $actor,
        string $frontDeskStayId,
        string $targetRoomId,
        string $moveReason,
        string $idempotencyContext
    ): string {
        if (! $actor->can(self::EXECUTE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk room move permission is required.');
        }

        $propertyId = $this->assignmentService->activePropertyId();
        $this->assignmentService->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $frontDeskStayId, $targetRoomId, $moveReason, $idempotencyContext) {
            $stay = $this->lockStay($propertyId, $frontDeskStayId);
            $currentAssignment = $this->lockCurrentAssignment($propertyId, $stay);
            [$sourceRoom, $targetRoom] = $this->lockSourceAndTargetRooms($propertyId, (string) $stay->current_room_id, $targetRoomId);
            $reservation = $this->assignmentService->lockReservation($propertyId, $stay->reservation_id);

            $this->assertRoomMoveReady($actor, $stay, $currentAssignment, $reservation, $sourceRoom, $targetRoom, $moveReason);

            $hash = $this->roomMoveConfirmationHash($actor, $stay, $targetRoom, $moveReason, $idempotencyContext);

            $this->audit('frontdesk_room_move_confirmation_prepared', $actor, $stay, [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'source_room_id' => $sourceRoom->id,
                'target_room_id' => $targetRoom->id,
                'current_room_assignment_id' => $stay->current_room_assignment_id,
                'intent' => self::INTENT,
                'commercial_evidence_hash' => $hash,
                'move_reason' => $moveReason,
                'idempotency_context' => $idempotencyContext,
            ]);

            return $hash;
        });
    }

    /**
     * @return array{stay: FrontDeskStay, assignment: FrontDeskRoomAssignment, replayed: bool}
     */
    public function move(
        User $actor,
        string $frontDeskStayId,
        string $targetRoomId,
        string $moveReason,
        string $idempotencyKey,
        string $idempotencyContext
    ): array {
        if (! $actor->can(self::EXECUTE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk room move permission is required.');
        }

        $propertyId = $this->assignmentService->activePropertyId();
        $companyId = session('active_company_id');
        $this->assignmentService->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $companyId, $frontDeskStayId, $targetRoomId, $moveReason, $idempotencyKey, $idempotencyContext) {
            $existingIdempotent = FrontDeskRoomAssignment::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingIdempotent) {
                $stay = $this->lockStay($propertyId, $existingIdempotent->front_desk_stay_id);
                if (
                    $stay->id === $frontDeskStayId
                    && $existingIdempotent->room_id === $targetRoomId
                    && $existingIdempotent->assignment_kind === FrontDeskRoomAssignmentKindEnum::RoomMove
                    && $stay->current_room_assignment_id === $existingIdempotent->id
                    && $stay->current_room_id === $targetRoomId
                    && $this->statusValue($stay->status) === FrontDeskStayStatusEnum::InHouse->value
                ) {
                    return [
                        'stay' => $stay->fresh(),
                        'assignment' => $existingIdempotent->fresh(),
                        'replayed' => true,
                    ];
                }

                throw new DomainException('Idempotency key was already used for a different Front Desk room move outcome.');
            }

            $stay = $this->lockStay($propertyId, $frontDeskStayId);
            $currentAssignment = $this->lockCurrentAssignment($propertyId, $stay);
            [$sourceRoom, $targetRoom] = $this->lockSourceAndTargetRooms($propertyId, (string) $stay->current_room_id, $targetRoomId);
            $reservation = $this->assignmentService->lockReservation($propertyId, $stay->reservation_id);
            $this->assertRoomMoveReady($actor, $stay, $currentAssignment, $reservation, $sourceRoom, $targetRoom, $moveReason);

            $expectedHash = $this->roomMoveConfirmationHash($actor, $stay, $targetRoom, $moveReason, $idempotencyContext);
            $metadata = $this->confirmationService->confirmationMetadataFor($actor, self::INTENT, $companyId, $propertyId);

            if (($metadata['commercial_evidence_hash'] ?? null) !== $expectedHash) {
                throw new DomainException('Sensitive action confirmation evidence does not match the current Front Desk room move.');
            }

            $sourceHash = $this->roomMoveSourceHash($actor, $stay, $targetRoom, $moveReason);
            $existingMove = FrontDeskRoomAssignment::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('front_desk_stay_id', $stay->id)
                ->where('assignment_kind', FrontDeskRoomAssignmentKindEnum::RoomMove->value)
                ->where('source_hash', $sourceHash)
                ->lockForUpdate()
                ->first();

            if ($existingMove) {
                throw new DomainException('Room move evidence already exists for the same source and target state.');
            }

            $assignment = FrontDeskRoomAssignment::create([
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'room_id' => $targetRoom->id,
                'room_type_id' => null,
                'assignment_kind' => FrontDeskRoomAssignmentKindEnum::RoomMove,
                'assignment_reason' => $moveReason,
                'occurred_at' => now(),
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            $stay->update([
                'status' => FrontDeskStayStatusEnum::InHouse,
                'current_room_id' => $targetRoom->id,
                'current_room_assignment_id' => $assignment->id,
                'updated_by' => $actor->id,
            ]);

            $this->confirmationService->invalidate($actor, self::INTENT, $companyId, $propertyId);

            $this->audit('frontdesk_room_move_executed', $actor, $stay->fresh(), [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'source_room_id' => $sourceRoom->id,
                'target_room_id' => $targetRoom->id,
                'previous_room_assignment_id' => $currentAssignment->id,
                'front_desk_room_assignment_id' => $assignment->id,
                'assignment_kind' => FrontDeskRoomAssignmentKindEnum::RoomMove->value,
                'source_hash' => $sourceHash,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'stay' => $stay->fresh(),
                'assignment' => $assignment->fresh(),
                'replayed' => false,
            ];
        });
    }

    public function roomMoveConfirmationHash(
        User $actor,
        FrontDeskStay $stay,
        Room $targetRoom,
        string $moveReason,
        string $idempotencyContext
    ): string {
        $payload = [
            'intent' => self::INTENT,
            'source_hash' => $this->roomMoveSourceHash($actor, $stay, $targetRoom, $moveReason),
            'idempotency_context' => $idempotencyContext,
        ];
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function roomMoveSourceHash(User $actor, FrontDeskStay $stay, Room $targetRoom, string $moveReason): string
    {
        $currentAssignment = FrontDeskRoomAssignment::withoutGlobalScopes()
            ->whereKey($stay->current_room_assignment_id)
            ->where('property_id', $stay->property_id)
            ->first();

        if (! $currentAssignment) {
            throw new DomainException('Current Front Desk room assignment evidence is missing.');
        }

        $housekeeping = $this->assignmentService->assertHousekeepingReady($targetRoom);
        $engineering = $this->assignmentService->assertEngineeringAvailable($actor, $targetRoom->id);

        $payload = [
            'property_id' => $stay->property_id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'source_room_id' => $stay->current_room_id,
            'target_room_id' => $targetRoom->id,
            'current_room_assignment_id' => $currentAssignment->id,
            'current_stay_status' => $this->statusValue($stay->status),
            'target_housekeeping_readiness_hash' => $this->stableHash($housekeeping),
            'target_engineering_availability_hash' => $this->stableHash([
                'availability_status' => $engineering['availability_status'] ?? null,
                'blocking_source_type' => $engineering['blocking_source_type'] ?? null,
                'blocking_source_id' => $engineering['blocking_source_id'] ?? null,
                'blocking_reason' => $engineering['blocking_reason'] ?? null,
            ]),
            'room_move_reason' => $moveReason,
            'assignment_kind' => FrontDeskRoomAssignmentKindEnum::RoomMove->value,
        ];

        return $this->stableHash($payload);
    }

    private function assertRoomMoveReady(
        User $actor,
        FrontDeskStay $stay,
        FrontDeskRoomAssignment $currentAssignment,
        Reservation $reservation,
        Room $sourceRoom,
        Room $targetRoom,
        string $moveReason
    ): void {
        if (trim($moveReason) === '') {
            throw new DomainException('Room move reason is required.');
        }

        if ($this->statusValue($stay->status) !== FrontDeskStayStatusEnum::InHouse->value) {
            throw new DomainException('Only an IN_HOUSE Front Desk stay can be moved.');
        }

        if ($stay->current_room_id !== $sourceRoom->id || $currentAssignment->room_id !== $sourceRoom->id) {
            throw new DomainException('Source room must match the current Front Desk room evidence.');
        }

        if ($sourceRoom->id === $targetRoom->id) {
            throw new DomainException('Target room must differ from the source room.');
        }

        if ($reservation->primary_guest_id !== $stay->guest_id || $currentAssignment->guest_id !== $stay->guest_id) {
            throw new DomainException('Canonical guest evidence no longer matches the Front Desk stay.');
        }

        $this->assignmentService->assertReservationStillEligible($reservation);
        $this->assignmentService->assertCanonicalGuest($reservation, $stay->property_id);
        $this->assignmentService->assertRoomTypeCompatibility($reservation, $targetRoom);
        $this->assignmentService->assertHousekeepingReady($targetRoom);
        $this->assignmentService->assertEngineeringAvailable($actor, $targetRoom->id);
        $this->assignmentService->assertRoomOccupancyAvailable($stay->property_id, $targetRoom->id, $stay->id);
    }

    private function lockStay(string $propertyId, string $frontDeskStayId): FrontDeskStay
    {
        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($frontDeskStayId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        if (! $stay) {
            throw new DomainException('Front Desk stay was not found for the active property.');
        }

        return $stay;
    }

    private function lockCurrentAssignment(string $propertyId, FrontDeskStay $stay): FrontDeskRoomAssignment
    {
        $assignment = FrontDeskRoomAssignment::withoutGlobalScopes()
            ->whereKey($stay->current_room_assignment_id)
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stay->id)
            ->lockForUpdate()
            ->first();

        if (! $assignment) {
            throw new DomainException('Current Front Desk room assignment evidence was not found for the active property.');
        }

        return $assignment;
    }

    /**
     * @return array{0: Room, 1: Room}
     */
    private function lockSourceAndTargetRooms(string $propertyId, string $sourceRoomId, string $targetRoomId): array
    {
        if ($sourceRoomId === '' || $sourceRoomId === $targetRoomId) {
            throw new DomainException('Target room must differ from the source room.');
        }

        $ids = [$sourceRoomId, $targetRoomId];
        sort($ids, SORT_STRING);

        $rooms = Room::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if (! $rooms->has($sourceRoomId)) {
            throw new DomainException('Source room is missing, inactive, or outside the active property.');
        }

        if (! $rooms->has($targetRoomId)) {
            throw new DomainException('Target room is missing, inactive, or outside the active property.');
        }

        return [$rooms->get($sourceRoomId), $rooms->get($targetRoomId)];
    }

    private function audit(string $event, User $actor, FrontDeskStay $stay, array $newValues): void
    {
        AuditLog::record([
            'property_id' => $stay->property_id,
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => FrontDeskStay::class,
            'auditable_id' => $stay->id,
            'old_values' => [],
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'tags' => ['frontdesk-room-move', $stay->property_id, (string) $stay->current_room_id],
        ]);
    }

    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function statusValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}

<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskRoomAssignment;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskCheckInService
{
    public const EXECUTE_PERMISSION = 'frontdesk.check-in.execute';
    public const IN_HOUSE_VIEW_PERMISSION = 'frontdesk.in-house.view';
    public const INTENT = 'frontdesk-check-in';

    public function __construct(
        private readonly FrontDeskRoomAssignmentService $assignmentService,
        private readonly ArrivalEligibilityProjectionService $arrivalEligibility,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function prepareConfirmation(User $actor, string $frontDeskStayId, string $idempotencyContext): string
    {
        if (! $actor->can(self::EXECUTE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk check-in execution permission is required.');
        }

        $propertyId = $this->assignmentService->activePropertyId();
        $this->assignmentService->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $frontDeskStayId, $idempotencyContext) {
            $stay = $this->lockStay($propertyId, $frontDeskStayId);
            $assignment = $this->lockAssignment($propertyId, $stay);
            $room = $this->assignmentService->lockRoom($propertyId, $assignment->room_id);
            $reservation = $this->assignmentService->lockReservation($propertyId, $stay->reservation_id);

            $this->assertReadyForCheckIn($actor, $stay, $assignment, $reservation, $room);

            if ($this->statusValue($stay->status) === FrontDeskStayStatusEnum::RoomAssigned->value) {
                $stay->update([
                    'status' => FrontDeskStayStatusEnum::CheckInConfirmationPending,
                    'updated_by' => $actor->id,
                ]);
                $stay = $stay->fresh();
            }

            $hash = $this->checkInEvidenceHash($actor, $stay, $idempotencyContext);

            $this->audit('frontdesk_check_in_confirmation_prepared', $actor, $stay, [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'front_desk_room_assignment_id' => $assignment->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'target_room_id' => $stay->current_room_id,
                'intent' => self::INTENT,
                'commercial_evidence_hash' => $hash,
                'idempotency_context' => $idempotencyContext,
            ]);

            return $hash;
        });
    }

    public function checkIn(User $actor, string $frontDeskStayId, string $idempotencyContext): FrontDeskStay
    {
        if (! $actor->can(self::EXECUTE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk check-in execution permission is required.');
        }

        $propertyId = $this->assignmentService->activePropertyId();
        $companyId = session('active_company_id');
        $this->assignmentService->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $companyId, $frontDeskStayId, $idempotencyContext) {
            $stay = $this->lockStay($propertyId, $frontDeskStayId);

            if ($this->statusValue($stay->status) === FrontDeskStayStatusEnum::InHouse->value) {
                throw new DomainException('Front Desk stay is already in-house.');
            }

            $assignment = $this->lockAssignment($propertyId, $stay);
            $room = $this->assignmentService->lockRoom($propertyId, $assignment->room_id);
            $reservation = $this->assignmentService->lockReservation($propertyId, $stay->reservation_id);
            $this->assertReadyForCheckIn($actor, $stay, $assignment, $reservation, $room);
            $this->assignmentService->assertRoomOccupancyAvailable($propertyId, $room->id, $stay->id);

            $expectedHash = $this->checkInEvidenceHash($actor, $stay, $idempotencyContext);
            $metadata = $this->confirmationService->confirmationMetadataFor($actor, self::INTENT, $companyId, $propertyId);

            if (($metadata['commercial_evidence_hash'] ?? null) !== $expectedHash) {
                throw new DomainException('Sensitive action confirmation evidence does not match the current Front Desk check-in.');
            }

            $stay->update([
                'status' => FrontDeskStayStatusEnum::InHouse,
                'checked_in_at' => now(),
                'checked_in_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->confirmationService->invalidate($actor, self::INTENT, $companyId, $propertyId);

            $this->audit('frontdesk_check_in_executed', $actor, $stay->fresh(), [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'front_desk_room_assignment_id' => $assignment->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'target_room_id' => $room->id,
                'status' => FrontDeskStayStatusEnum::InHouse->value,
                'idempotency_context' => $idempotencyContext,
            ]);

            return $stay->fresh();
        });
    }

    public function checkInEvidenceHash(User $actor, FrontDeskStay $stay, string $idempotencyContext): string
    {
        $propertyId = $stay->property_id;
        $assignment = FrontDeskRoomAssignment::withoutGlobalScopes()
            ->whereKey($stay->current_room_assignment_id)
            ->where('property_id', $propertyId)
            ->first();

        if (! $assignment) {
            throw new DomainException('Front Desk room assignment evidence is missing.');
        }

        $room = $assignment->room()->withoutGlobalScopes()->first();
        $reservation = $stay->reservation()->withoutGlobalScopes()->first();

        if (! $room || ! $reservation) {
            throw new DomainException('Front Desk check-in source evidence is missing.');
        }

        $housekeeping = $this->assignmentService->assertHousekeepingReady($room);
        $engineering = $this->assignmentService->assertEngineeringAvailable($actor, $room->id);
        $eligibility = $this->arrivalEligibility->reservation($actor, $reservation->id);

        $payload = [
            'property_id' => $propertyId,
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'front_desk_stay_id' => $stay->id,
            'front_desk_room_assignment_id' => $assignment->id,
            'target_room_id' => $room->id,
            'reservation_eligibility_hash' => $this->stableHash([
                'reservation_status' => $eligibility['reservation_status'] ?? null,
                'arrival_date' => $eligibility['arrival_date'] ?? null,
                'departure_date' => $eligibility['departure_date'] ?? null,
                'eligible' => $eligibility['eligibility']['eligible'] ?? null,
                'blockers' => $eligibility['eligibility']['blockers'] ?? [],
            ]),
            'room_assignment_evidence_hash' => $assignment->source_hash,
            'housekeeping_readiness_evidence_hash' => $this->stableHash($housekeeping),
            'engineering_availability_evidence_hash' => $this->stableHash([
                'availability_status' => $engineering['availability_status'] ?? null,
                'blocking_source_type' => $engineering['blocking_source_type'] ?? null,
                'blocking_source_id' => $engineering['blocking_source_id'] ?? null,
                'blocking_reason' => $engineering['blocking_reason'] ?? null,
            ]),
            'stay_status' => $this->statusValue($stay->status),
            'idempotency_context' => $idempotencyContext,
            'intent' => self::INTENT,
        ];

        return $this->stableHash($payload);
    }

    private function assertReadyForCheckIn(User $actor, FrontDeskStay $stay, FrontDeskRoomAssignment $assignment, mixed $reservation, mixed $room): void
    {
        $status = $this->statusValue($stay->status);
        if (! in_array($status, [FrontDeskStayStatusEnum::RoomAssigned->value, FrontDeskStayStatusEnum::CheckInConfirmationPending->value], true)) {
            throw new DomainException('Front Desk stay is not ready for controlled check-in.');
        }

        if ($stay->current_room_id !== $assignment->room_id || $stay->current_room_assignment_id !== $assignment->id) {
            throw new DomainException('Front Desk stay room assignment evidence does not match the current room.');
        }

        if ($reservation->primary_guest_id !== $stay->guest_id || $assignment->guest_id !== $stay->guest_id) {
            throw new DomainException('Canonical guest evidence no longer matches the Front Desk stay.');
        }

        $this->assignmentService->assertReservationStillEligible($reservation);
        $this->assignmentService->assertCanonicalGuest($reservation, $stay->property_id);
        $this->assignmentService->assertRoomTypeCompatibility($reservation, $room);
        $this->assignmentService->assertHousekeepingReady($room);
        $this->assignmentService->assertEngineeringAvailable($actor, $room->id);
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

    private function lockAssignment(string $propertyId, FrontDeskStay $stay): FrontDeskRoomAssignment
    {
        $assignment = FrontDeskRoomAssignment::withoutGlobalScopes()
            ->whereKey($stay->current_room_assignment_id)
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stay->id)
            ->lockForUpdate()
            ->first();

        if (! $assignment) {
            throw new DomainException('Front Desk room assignment evidence was not found for the active property.');
        }

        return $assignment;
    }

    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
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
            'tags' => ['frontdesk-check-in', $stay->property_id, (string) $stay->current_room_id],
        ]);
    }

    private function statusValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}

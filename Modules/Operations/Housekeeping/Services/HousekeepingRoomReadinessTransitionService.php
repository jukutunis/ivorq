<?php

namespace Modules\Operations\Housekeeping\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\HousekeepingRoomReadinessTransitionTypeEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Models\HousekeepingRoomReadinessTransition;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HousekeepingRoomReadinessTransitionService
{
    public const CLEAN_PERMISSION = 'housekeeping.room-readiness.clean';
    public const SUBMIT_INSPECTION_PERMISSION = 'housekeeping.room-readiness.submit-inspection';
    public const RELEASE_READY_PERMISSION = 'housekeeping.room-readiness.release-ready';
    public const RELEASE_INTENT = 'housekeeping-room-release-ready';

    private const NOT_READY_STATES = ['waiting_cleaning', 'dirty'];
    private const CLEANING_STATE = 'cleaning';
    private const WAITING_INSPECTION_STATE = 'waiting_inspection';
    private const READY_STATES = ['ready_for_sale', 'ready_for_arrival', 'ready_for_vip'];

    public function __construct(
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function startCleaning(
        User $actor,
        string $roomId,
        string $idempotencyKey,
        ?string $sourceType = null,
        ?string $sourceId = null
    ): HousekeepingRoomReadinessTransition {
        if (! $actor->can(self::CLEAN_PERMISSION)) {
            throw new HttpException(403, 'Housekeeping room readiness clean permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $roomId, $idempotencyKey, $sourceType, $sourceId) {
            $room = $this->lockRoom($propertyId, $roomId);

            $existingIdempotent = $this->existingIdempotent($propertyId, $idempotencyKey);
            if ($existingIdempotent) {
                if ($this->isExactReplay(
                    $existingIdempotent,
                    $room->id,
                    HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning,
                    null,
                    $sourceType,
                    $sourceId,
                )) {
                    return $existingIdempotent;
                }
                throw new DomainException('Idempotency key was already used for a different Housekeeping readiness transition outcome.');
            }

            $currentReadiness = (string) ($room->readiness_state ?? 'unknown');
            if (! in_array($currentReadiness, self::NOT_READY_STATES, true)) {
                throw new DomainException("Room readiness state \"{$currentReadiness}\" is not eligible for start-cleaning transition.");
            }

            $sourceHash = $this->transitionSourceHash(
                $propertyId, $room->id, $currentReadiness, self::CLEANING_STATE,
                HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning,
                null, $sourceType, $sourceId, $idempotencyKey
            );

            $transition = HousekeepingRoomReadinessTransition::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => self::CLEANING_STATE,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning,
                'reason' => null,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_at' => now(),
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            $room->update([
                'readiness_state' => self::CLEANING_STATE,
            ]);

            $this->audit('housekeeping_room_readiness_start_cleaning', $actor, $transition, [
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => self::CLEANING_STATE,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning->value,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return $transition->fresh();
        });
    }

    public function submitInspection(
        User $actor,
        string $roomId,
        string $idempotencyKey,
        ?string $reason = null,
        ?string $sourceType = null,
        ?string $sourceId = null
    ): HousekeepingRoomReadinessTransition {
        if (! $actor->can(self::SUBMIT_INSPECTION_PERMISSION)) {
            throw new HttpException(403, 'Housekeeping room readiness submit-inspection permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $roomId, $idempotencyKey, $reason, $sourceType, $sourceId) {
            $room = $this->lockRoom($propertyId, $roomId);

            $existingIdempotent = $this->existingIdempotent($propertyId, $idempotencyKey);
            if ($existingIdempotent) {
                if ($this->isExactReplay(
                    $existingIdempotent,
                    $room->id,
                    HousekeepingRoomReadinessTransitionTypeEnum::SubmitInspection,
                    $reason,
                    $sourceType,
                    $sourceId,
                )) {
                    return $existingIdempotent;
                }
                throw new DomainException('Idempotency key was already used for a different Housekeeping readiness transition outcome.');
            }

            $currentReadiness = (string) ($room->readiness_state ?? 'unknown');
            if ($currentReadiness !== self::CLEANING_STATE) {
                throw new DomainException("Room readiness state \"{$currentReadiness}\" is not eligible for submit-inspection transition.");
            }

            $sourceHash = $this->transitionSourceHash(
                $propertyId, $room->id, $currentReadiness, self::WAITING_INSPECTION_STATE,
                HousekeepingRoomReadinessTransitionTypeEnum::SubmitInspection,
                $reason, $sourceType, $sourceId, $idempotencyKey
            );

            $transition = HousekeepingRoomReadinessTransition::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => self::WAITING_INSPECTION_STATE,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::SubmitInspection,
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_at' => now(),
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            $room->update([
                'cleanliness_status' => RoomCleanlinessStatusEnum::Clean,
                'readiness_state' => self::WAITING_INSPECTION_STATE,
            ]);

            $this->audit('housekeeping_room_readiness_submit_inspection', $actor, $transition, [
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => self::WAITING_INSPECTION_STATE,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::SubmitInspection->value,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return $transition->fresh();
        });
    }

    public function releaseReady(
        User $actor,
        string $roomId,
        string $releaseReason,
        string $idempotencyContext,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $sourceCleaningTaskId = null,
    ): HousekeepingRoomReadinessTransition {
        if (! $actor->can(self::RELEASE_READY_PERMISSION)) {
            throw new HttpException(403, 'Housekeeping room readiness release-ready permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $roomId, $releaseReason, $idempotencyContext, $sourceType, $sourceId, $sourceCleaningTaskId) {
            $room = $this->lockRoom($propertyId, $roomId);

            $existingIdempotent = $this->existingIdempotent($propertyId, $idempotencyContext);
            if ($existingIdempotent) {
                if ($this->isExactReplay(
                    $existingIdempotent,
                    $room->id,
                    HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady,
                    $releaseReason,
                    $sourceType,
                    $sourceId,
                )) {
                    return $existingIdempotent;
                }

                throw new DomainException('Idempotency key was already used for a different Housekeeping readiness transition outcome.');
            }

            $currentReadiness = (string) ($room->readiness_state ?? 'unknown');
            if ($currentReadiness !== self::WAITING_INSPECTION_STATE) {
                throw new DomainException("Room readiness state \"{$currentReadiness}\" is not eligible for release-ready transition.");
            }

            $targetReadiness = $this->targetReadinessFor($room);

            $hash = $this->releaseEvidenceHash(
                $room,
                $currentReadiness,
                $targetReadiness,
                $releaseReason,
                $idempotencyContext,
                $sourceId,
                $sourceCleaningTaskId,
            );
            $metadata = $this->confirmationService->confirmationMetadataFor(
                $actor,
                self::RELEASE_INTENT,
                session('active_company_id'),
                $propertyId
            );

            if (($metadata['commercial_evidence_hash'] ?? null) !== $hash) {
                throw new DomainException('Sensitive action confirmation evidence does not match the current Housekeeping release-ready transition.');
            }

            $sourceHash = $this->transitionSourceHash(
                $propertyId, $room->id, $currentReadiness, $targetReadiness,
                HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady,
                $releaseReason, $sourceType, $sourceId, $idempotencyContext
            );

            $transition = HousekeepingRoomReadinessTransition::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => $targetReadiness,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady,
                'reason' => $releaseReason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_at' => now(),
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyContext,
                'source_hash' => $sourceHash,
            ]);

            $room->update([
                'cleanliness_status' => RoomCleanlinessStatusEnum::Inspected,
                'readiness_state' => $targetReadiness,
            ]);

            DB::afterCommit(function () use ($actor, $propertyId): void {
                $this->confirmationService->invalidate(
                    $actor,
                    self::RELEASE_INTENT,
                    session('active_company_id'),
                    $propertyId,
                );
            });

            $this->audit('housekeeping_room_readiness_release_ready', $actor, $transition, [
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => $targetReadiness,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady->value,
                'release_reason' => $releaseReason,
                'idempotency_context' => $idempotencyContext,
                'source_hash' => $sourceHash,
            ]);

            return $transition->fresh();
        });
    }

    public function inspectionFailed(
        User $actor,
        string $roomId,
        string $failureReason,
        string $idempotencyKey,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): HousekeepingRoomReadinessTransition {
        if (! $actor->can(self::CLEAN_PERMISSION)) {
            throw new HttpException(403, 'Housekeeping room readiness clean permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $roomId, $failureReason, $idempotencyKey, $sourceType, $sourceId) {
            $room = $this->lockRoom($propertyId, $roomId);

            $existingIdempotent = $this->existingIdempotent($propertyId, $idempotencyKey);
            if ($existingIdempotent) {
                if ($this->isExactReplay(
                    $existingIdempotent,
                    $room->id,
                    HousekeepingRoomReadinessTransitionTypeEnum::InspectionFailed,
                    $failureReason,
                    $sourceType,
                    $sourceId,
                )) {
                    return $existingIdempotent;
                }

                throw new DomainException('Idempotency key was already used for a different Housekeeping readiness transition outcome.');
            }

            $currentReadiness = (string) ($room->readiness_state ?? 'unknown');
            if ($currentReadiness !== self::WAITING_INSPECTION_STATE) {
                throw new DomainException("Room readiness state \"{$currentReadiness}\" is not eligible for inspection-failed transition.");
            }

            $targetReadiness = 'waiting_cleaning';
            $sourceHash = $this->transitionSourceHash(
                $propertyId,
                $room->id,
                $currentReadiness,
                $targetReadiness,
                HousekeepingRoomReadinessTransitionTypeEnum::InspectionFailed,
                $failureReason,
                $sourceType,
                $sourceId,
                $idempotencyKey,
            );

            $transition = HousekeepingRoomReadinessTransition::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => $targetReadiness,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::InspectionFailed,
                'reason' => $failureReason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_at' => now(),
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            $room->update([
                'cleanliness_status' => RoomCleanlinessStatusEnum::Dirty,
                'readiness_state' => $targetReadiness,
            ]);

            $this->audit('housekeeping_room_readiness_inspection_failed', $actor, $transition, [
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $currentReadiness,
                'to_status' => $targetReadiness,
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::InspectionFailed->value,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return $transition->fresh();
        });
    }

    public function releaseEvidenceHash(
        Room $room,
        string $currentReadiness,
        string $targetReadiness,
        string $releaseReason,
        string $idempotencyContext,
        ?string $inspectionId = null,
        ?string $cleaningTaskId = null,
    ): string {
        $payload = [
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'current_readiness_state' => $currentReadiness,
            'target_readiness_state' => $targetReadiness,
            'cleanliness_status' => $room->cleanliness_status instanceof RoomCleanlinessStatusEnum
                ? $room->cleanliness_status->value
                : (string) $room->cleanliness_status,
            'release_reason' => $releaseReason,
            'idempotency_context' => $idempotencyContext,
            'inspection_id' => $inspectionId,
            'cleaning_task_id' => $cleaningTaskId,
        ];

        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function targetReadinessFor(Room $room): string
    {
        return $room->is_vip ? 'ready_for_vip' : 'ready_for_sale';
    }

    private function transitionSourceHash(
        string $propertyId,
        string $roomId,
        string $fromStatus,
        string $toStatus,
        HousekeepingRoomReadinessTransitionTypeEnum $transitionType,
        ?string $reason,
        ?string $sourceType,
        ?string $sourceId,
        string $idempotencyKey
    ): string {
        $payload = [
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'transition_type' => $transitionType->value,
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
        ];

        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function activePropertyId(): string
    {
        return app(CurrentPropertyService::class)->resolveOrFail();
    }

    private function assertActiveProperty(string $propertyId): void
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

    private function lockRoom(string $propertyId, string $roomId): Room
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

    private function existingIdempotent(string $propertyId, string $idempotencyKey): ?HousekeepingRoomReadinessTransition
    {
        return HousekeepingRoomReadinessTransition::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    private function isExactReplay(
        HousekeepingRoomReadinessTransition $transition,
        string $roomId,
        HousekeepingRoomReadinessTransitionTypeEnum $type,
        ?string $reason,
        ?string $sourceType,
        ?string $sourceId,
    ): bool {
        return $transition->room_id === $roomId
            && $transition->transition_type === $type
            && $transition->reason === $reason
            && $transition->source_type === $sourceType
            && $transition->source_id === $sourceId;
    }

    /**
     * @param array<string, mixed> $newValues
     */
    private function audit(string $event, User $actor, HousekeepingRoomReadinessTransition $transition, array $newValues): void
    {
        AuditLog::record([
            'property_id' => $transition->property_id,
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => HousekeepingRoomReadinessTransition::class,
            'auditable_id' => $transition->id,
            'old_values' => [],
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'tags' => ['housekeeping-room-readiness', $transition->property_id, $transition->room_id],
        ]);
    }
}

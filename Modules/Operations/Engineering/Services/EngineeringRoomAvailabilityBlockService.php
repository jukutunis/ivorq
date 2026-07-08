<?php

namespace Modules\Operations\Engineering\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\EngineeringRoomAvailabilityBlockStatusEnum;
use Modules\Operations\Engineering\Models\EngineeringRoomAvailabilityBlock;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EngineeringRoomAvailabilityBlockService
{
    public const BLOCK_PERMISSION = 'engineering.room-availability.block';
    public const RELEASE_PERMISSION = 'engineering.room-availability.release';
    public const RELEASE_INTENT = 'engineering-room-availability-release';

    public function __construct(private readonly SensitiveActionConfirmationService $confirmationService) {}

    public function block(
        User $actor,
        string $roomId,
        string $blockReason,
        ?string $sourceType,
        ?string $sourceId,
        string $idempotencyKey
    ): EngineeringRoomAvailabilityBlock {
        if (! $actor->can(self::BLOCK_PERMISSION)) {
            throw new HttpException(403, 'Engineering room availability block permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $roomId, $blockReason, $sourceType, $sourceId, $idempotencyKey) {
            $room = $this->lockRoom($propertyId, $roomId);
            $this->assertSource($propertyId, $room->id, $sourceType, $sourceId);

            $existingIdempotent = EngineeringRoomAvailabilityBlock::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingIdempotent) {
                if (
                    $existingIdempotent->room_id === $room->id
                    && $existingIdempotent->block_status === EngineeringRoomAvailabilityBlockStatusEnum::Active
                    && $existingIdempotent->block_reason === $blockReason
                    && $existingIdempotent->source_type === $sourceType
                    && $existingIdempotent->source_id === $sourceId
                ) {
                    return $existingIdempotent;
                }

                throw new DomainException('Idempotency key was already used for a different Engineering availability outcome.');
            }

            $active = EngineeringRoomAvailabilityBlock::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('room_id', $room->id)
                ->where('block_status', EngineeringRoomAvailabilityBlockStatusEnum::Active->value)
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw new DomainException('An active Engineering availability block already exists for this room.');
            }

            $block = EngineeringRoomAvailabilityBlock::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'block_status' => EngineeringRoomAvailabilityBlockStatusEnum::Active,
                'block_reason' => $blockReason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'started_at' => now(),
                'started_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit('engineering_room_availability_block_created', $actor, $block, [
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'block_reason' => $blockReason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $block->fresh();
        });
    }

    public function release(
        User $actor,
        string $blockId,
        string $releaseReason,
        string $idempotencyContext
    ): EngineeringRoomAvailabilityBlock {
        if (! $actor->can(self::RELEASE_PERMISSION)) {
            throw new HttpException(403, 'Engineering room availability release permission is required.');
        }

        $propertyId = $this->activePropertyId();
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($actor, $propertyId, $blockId, $releaseReason, $idempotencyContext) {
            $block = EngineeringRoomAvailabilityBlock::withoutGlobalScopes()
                ->whereKey($blockId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (! $block) {
                throw new DomainException('Engineering availability block was not found for the active property.');
            }

            $room = $this->lockRoom($propertyId, $block->room_id);
            $this->assertSource($propertyId, $room->id, $block->source_type, $block->source_id);

            if ($block->block_status !== EngineeringRoomAvailabilityBlockStatusEnum::Active) {
                throw new DomainException('Engineering availability block is not active.');
            }

            $hash = $this->releaseEvidenceHash($block, $releaseReason, $idempotencyContext);
            $metadata = $this->confirmationService->confirmationMetadataFor(
                $actor,
                self::RELEASE_INTENT,
                session('active_company_id'),
                $propertyId
            );

            if (($metadata['commercial_evidence_hash'] ?? null) !== $hash) {
                throw new DomainException('Sensitive action confirmation evidence does not match the current Engineering availability release.');
            }

            $block->update([
                'block_status' => EngineeringRoomAvailabilityBlockStatusEnum::Released,
                'released_at' => now(),
                'released_by' => $actor->id,
                'release_reason' => $releaseReason,
            ]);

            $this->confirmationService->invalidate($actor, self::RELEASE_INTENT, session('active_company_id'), $propertyId);

            $this->audit('engineering_room_availability_block_released', $actor, $block->fresh(), [
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'block_id' => $block->id,
                'release_reason' => $releaseReason,
                'idempotency_context' => $idempotencyContext,
            ]);

            return $block->fresh();
        });
    }

    public function releaseEvidenceHash(
        EngineeringRoomAvailabilityBlock $block,
        string $releaseReason,
        string $idempotencyContext
    ): string {
        $payload = [
            'property_id' => $block->property_id,
            'room_id' => $block->room_id,
            'engineering_room_availability_block_id' => $block->id,
            'current_block_status' => $block->block_status instanceof EngineeringRoomAvailabilityBlockStatusEnum
                ? $block->block_status->value
                : (string) $block->block_status,
            'block_reason' => $block->block_reason,
            'source_type' => $block->source_type,
            'source_id' => $block->source_id,
            'release_reason' => $releaseReason,
            'idempotency_context' => $idempotencyContext,
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

    private function assertSource(string $propertyId, string $roomId, ?string $sourceType, ?string $sourceId): void
    {
        if ($sourceType === null && $sourceId === null) {
            return;
        }

        if ($sourceType === null || $sourceId === null) {
            throw new DomainException('Engineering availability source type and source id must both be supplied.');
        }

        match ($sourceType) {
            'ENGINEERING_WORK_ORDER' => $this->assertWorkOrderSource($propertyId, $roomId, $sourceId),
            'ENGINEERING_PREVENTIVE_MAINTENANCE' => $this->assertPreventiveMaintenanceSource($propertyId, $roomId, $sourceId),
            'ENGINEERING_PM_TASK' => $this->assertPreventiveMaintenanceTaskSource($propertyId, $roomId, $sourceId),
            default => throw new DomainException('Engineering availability source type is not supported.'),
        };
    }

    private function assertWorkOrderSource(string $propertyId, string $roomId, string $sourceId): void
    {
        if (! Schema::hasColumn('work_orders', 'room_id')) {
            throw new DomainException('Engineering work order source is not configured as room availability evidence.');
        }

        $source = WorkOrder::withoutGlobalScopes()
            ->whereKey($sourceId)
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->first();

        if (! $source) {
            throw new DomainException('Engineering work order source does not belong to the active property and room.');
        }
    }

    private function assertPreventiveMaintenanceSource(string $propertyId, string $roomId, string $sourceId): void
    {
        if (! Schema::hasTable('preventive_maintenances') || ! Schema::hasColumn('preventive_maintenances', 'room_id')) {
            throw new DomainException('Engineering PM source is not configured as room availability evidence.');
        }

        $source = PreventiveMaintenance::withoutGlobalScopes()
            ->whereKey($sourceId)
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->first();

        if (! $source) {
            throw new DomainException('Engineering PM source does not belong to the active property and room.');
        }
    }

    private function assertPreventiveMaintenanceTaskSource(string $propertyId, string $roomId, string $sourceId): void
    {
        if (
            ! Schema::hasTable('preventive_maintenance_tasks')
            || ! Schema::hasTable('preventive_maintenances')
            || ! Schema::hasColumn('preventive_maintenances', 'room_id')
        ) {
            throw new DomainException('Engineering PM task source is not configured as room availability evidence.');
        }

        $source = PreventiveMaintenanceTask::withoutGlobalScopes()
            ->whereKey($sourceId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $source) {
            throw new DomainException('Engineering PM task source does not belong to the active property.');
        }

        $pm = PreventiveMaintenance::withoutGlobalScopes()
            ->whereKey($source->preventive_maintenance_id)
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->first();

        if (! $pm) {
            throw new DomainException('Engineering PM task source does not belong to the active property and room.');
        }
    }

    /**
     * @param array<string, mixed> $newValues
     */
    private function audit(string $event, User $actor, EngineeringRoomAvailabilityBlock $block, array $newValues): void
    {
        AuditLog::record([
            'property_id' => $block->property_id,
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => EngineeringRoomAvailabilityBlock::class,
            'auditable_id' => $block->id,
            'old_values' => [],
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'tags' => ['engineering-room-availability', $block->property_id, $block->room_id],
        ]);
    }
}

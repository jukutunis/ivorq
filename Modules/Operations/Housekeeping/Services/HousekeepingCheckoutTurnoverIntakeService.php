<?php

namespace Modules\Operations\Housekeeping\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Enums\HousekeepingRoomReadinessTransitionTypeEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Models\HousekeepingCheckoutTurnoverIntake;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\ValueObjects\HousekeepingCheckoutTurnoverConsumptionResult;
use Shared\Services\CurrentPropertyService;

class HousekeepingCheckoutTurnoverIntakeService
{
    public const CONSUMER_IDENTITY = 'housekeeping-checkout-turnover-consumer-v1';
    public const SOURCE_TYPE = 'front_desk_checkout_housekeeping_handoff';
    public const ERROR_SOURCE_CONFLICT = 'HK_P11_SOURCE_CONFLICT';
    public const ERROR_ROOM_UNAVAILABLE = 'HK_P11_ROOM_UNAVAILABLE';
    public const ERROR_ROOM_LIFECYCLE_CONFLICT = 'HK_P11_ROOM_LIFECYCLE_CONFLICT';
    public const ERROR_ACTIVE_TASK_CONFLICT = 'HK_P11_ACTIVE_TASK_CONFLICT';
    public const ERROR_INTERNAL_RETRYABLE_FAILURE = 'HK_P11_INTERNAL_RETRYABLE_FAILURE';

    private const READY_READINESS = ['ready_for_sale', 'ready_for_arrival', 'ready_for_vip'];
    private const ACTIVE_TASK_STATUSES = ['pending', 'assigned', 'in_progress'];

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
        private readonly FrontDeskCheckoutHousekeepingHandoffDeliveryService $deliveryService,
    ) {}

    public function consumeNextAvailable(string $propertyId, int $leaseSeconds = 60): ?HousekeepingCheckoutTurnoverConsumptionResult
    {
        $claim = $this->deliveryService->claimNextAvailable($propertyId, $leaseSeconds);
        if ($claim === null) {
            return null;
        }

        $handoffId = (string) $claim['handoff_id'];
        $claimToken = (string) $claim['claim_token'];

        try {
            $result = $this->consumeClaimed($propertyId, $handoffId, $claimToken);
            $delivered = $this->deliveryService->markDelivered($propertyId, $handoffId, $claimToken);

            return new HousekeepingCheckoutTurnoverConsumptionResult(
                propertyId: $result->propertyId,
                handoffId: $result->handoffId,
                intakeId: $result->intakeId,
                cleaningTaskId: $result->cleaningTaskId,
                readinessTransitionId: $result->readinessTransitionId,
                roomId: $result->roomId,
                handoffDeliveryStatus: $delivered->delivery_status?->value ?? FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered->value,
                roomReadinessStatus: $result->roomReadinessStatus,
                roomCleanlinessStatus: $result->roomCleanlinessStatus,
                replayed: $result->replayed,
                deliveryConfirmationPending: false,
                attempts: (int) $delivered->attempts,
            );
        } catch (DomainException $exception) {
            $safeCode = $this->safeFailureCode($exception);
            $this->deliveryService->markFailed(
                $propertyId,
                $handoffId,
                $claimToken,
                $safeCode,
                $this->postgresWallClockUtc()->addMinutes(5),
            );

            throw $exception;
        }
    }

    public function consumeClaimed(string $propertyId, string $handoffId, string $claimToken): HousekeepingCheckoutTurnoverConsumptionResult
    {
        $currentPropertyId = $this->currentProperty->resolveOrFail();
        if ($propertyId !== $currentPropertyId) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }
        $this->assertActiveProperty($propertyId);

        return DB::transaction(function () use ($propertyId, $handoffId, $claimToken): HousekeepingCheckoutTurnoverConsumptionResult {
            $source = $this->resolveAndValidateSource($propertyId, $handoffId, $claimToken);

            $existing = $this->existingCommittedIntake(
                $propertyId,
                $source['handoff']->id,
                $source['execution']->id,
            );
            if ($existing) {
                return $this->resultFromIntake($existing, true);
            }

            $room = $this->lockAuthoritativeRoom($propertyId, $source['stay']->current_room_id);
            $beforeReadiness = (string) ($room->readiness_state ?? 'unknown');
            $beforeCleanliness = $room->cleanliness_status instanceof RoomCleanlinessStatusEnum
                ? $room->cleanliness_status->value
                : (string) $room->cleanliness_status;

            $this->assertRoomStateMatrix($beforeReadiness, $beforeCleanliness);
            $this->assertNoContradictoryActiveTask($propertyId, $room->id);

            $occurredAt = $this->postgresWallClockUtc();
            $taskId = (string) Str::ulid();
            $transitionId = (string) Str::ulid();
            $intakeId = (string) Str::ulid();
            $idempotencyKey = 'p11-checkout-turnover|' . $propertyId . '|' . $source['handoff']->id;
            $transitionSourceHash = $this->transitionSourceHash(
                $propertyId,
                $room->id,
                $beforeReadiness,
                'waiting_cleaning',
                $idempotencyKey,
                $source['handoff']->id,
            );
            $intakeSourceHash = $this->intakeSourceHash(
                $propertyId,
                $source['handoff']->id,
                $source['execution']->id,
                $source['stay']->id,
                $source['execution']->reservation_id,
                $room->id,
                $source['handoff']->source_hash,
                $source['execution']->source_hash,
                $taskId,
                $transitionId,
                $beforeReadiness,
                $beforeCleanliness,
            );

            DB::table('cleaning_tasks')->insert([
                'id' => $taskId,
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'task_type' => 'checkout_cleaning',
                'status' => 'pending',
                'priority' => $room->is_vip ? 'rush' : 'normal',
                'credits' => 1.0,
                'sla_minutes_target' => 45,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            DB::table('housekeeping_room_readiness_transitions')->insert([
                'id' => $transitionId,
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'from_status' => $beforeReadiness,
                'to_status' => 'waiting_cleaning',
                'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::CheckoutTurnoverIntake->value,
                'reason' => null,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $source['handoff']->id,
                'occurred_at' => $occurredAt,
                'created_by' => null,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $transitionSourceHash,
                'created_at' => $occurredAt,
            ]);

            DB::table('rooms')
                ->where('id', $room->id)
                ->where('property_id', $propertyId)
                ->update([
                    'cleanliness_status' => 'dirty',
                    'readiness_state' => 'waiting_cleaning',
                    'updated_at' => $occurredAt,
                ]);

            DB::table('housekeeping_checkout_turnover_intakes')->insert([
                'id' => $intakeId,
                'property_id' => $propertyId,
                'front_desk_checkout_housekeeping_handoff_id' => $source['handoff']->id,
                'checkout_execution_id' => $source['execution']->id,
                'front_desk_stay_id' => $source['stay']->id,
                'reservation_id' => $source['execution']->reservation_id,
                'room_id' => $room->id,
                'property_business_date_id' => $source['execution']->property_business_date_id,
                'business_date' => $source['execution']->business_date?->format('Y-m-d'),
                'cleaning_task_id' => $taskId,
                'room_readiness_transition_id' => $transitionId,
                'handoff_source_hash' => $source['handoff']->source_hash,
                'checkout_execution_source_hash' => $source['execution']->source_hash,
                'source_hash' => $intakeSourceHash,
                'room_readiness_before' => $beforeReadiness,
                'room_readiness_after' => 'waiting_cleaning',
                'cleanliness_before' => $beforeCleanliness,
                'cleanliness_after' => 'dirty',
                'consumer_identity' => self::CONSUMER_IDENTITY,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);

            $intake = HousekeepingCheckoutTurnoverIntake::withoutGlobalScopes()->findOrFail($intakeId);
            $this->recordAudit($intake);

            return $this->resultFromIntake($intake, false);
        });
    }

    /**
     * @return array{handoff: FrontDeskCheckoutHousekeepingHandoff, execution: FrontDeskCheckoutExecution, stay: FrontDeskStay}
     */
    private function resolveAndValidateSource(string $propertyId, string $handoffId, string $claimToken): array
    {
        $handoff = FrontDeskCheckoutHousekeepingHandoff::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->whereKey($handoffId)
            ->lockForUpdate()
            ->first();

        if (! $handoff) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        if (! in_array($handoff->delivery_status, [
            FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed,
            FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered,
        ], true)) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        if (! hash_equals(hash('sha256', $claimToken), $handoff->claim_token_hash ?? '')) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
        }

        if ($handoff->delivery_status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed) {
            $expired = DB::selectOne(
                "SELECT 1 FROM front_desk_checkout_housekeeping_handoffs WHERE id = ? AND delivery_status = 'CLAIMED' AND claim_expires_at <= (clock_timestamp() AT TIME ZONE 'UTC')",
                [$handoff->id],
            );
            if ($expired !== null) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }
        }

        $execution = FrontDeskCheckoutExecution::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->whereKey($handoff->checkout_execution_id)
            ->first();

        if (! $execution
            || $execution->front_desk_stay_id !== $handoff->front_desk_stay_id
            || $execution->reservation_id !== $handoff->reservation_id
            || $execution->property_business_date_id !== $handoff->property_business_date_id
            || $execution->business_date?->format('Y-m-d') !== $handoff->business_date?->format('Y-m-d')
            || $execution->terminal_stay_status !== FrontDeskStayStatusEnum::CheckedOut) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        if ($handoff->source_hash !== $this->handoffSourceHash($execution, $handoff->occurred_at)) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->whereKey($execution->front_desk_stay_id)
            ->first();

        if (! $stay
            || $stay->reservation_id !== $execution->reservation_id
            || $stay->status !== FrontDeskStayStatusEnum::CheckedOut
            || $stay->current_room_id === null) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        return ['handoff' => $handoff, 'execution' => $execution, 'stay' => $stay];
    }

    private function existingCommittedIntake(string $propertyId, string $handoffId, string $executionId): ?HousekeepingCheckoutTurnoverIntake
    {
        $byHandoff = HousekeepingCheckoutTurnoverIntake::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_checkout_housekeeping_handoff_id', $handoffId)
            ->lockForUpdate()
            ->first();

        $byExecution = HousekeepingCheckoutTurnoverIntake::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('checkout_execution_id', $executionId)
            ->lockForUpdate()
            ->first();

        if ($byHandoff && $byExecution && $byHandoff->id !== $byExecution->id) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        $intake = $byHandoff ?? $byExecution;
        if ($intake && (
            $intake->front_desk_checkout_housekeeping_handoff_id !== $handoffId
            || $intake->checkout_execution_id !== $executionId
        )) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }

        return $intake;
    }

    private function lockAuthoritativeRoom(string $propertyId, string $roomId): Room
    {
        $room = Room::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->whereKey($roomId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $room) {
            throw new DomainException(self::ERROR_ROOM_UNAVAILABLE);
        }

        return $room;
    }

    private function assertRoomStateMatrix(string $readiness, string $cleanliness): void
    {
        $readyFresh = in_array($readiness, self::READY_READINESS, true)
            && in_array($cleanliness, ['clean', 'inspected'], true);
        $compatibleWaiting = in_array($readiness, ['dirty', 'waiting_cleaning'], true)
            && $cleanliness === 'dirty';

        if (! $readyFresh && ! $compatibleWaiting) {
            throw new DomainException(self::ERROR_ROOM_LIFECYCLE_CONFLICT);
        }
    }

    private function assertNoContradictoryActiveTask(string $propertyId, string $roomId): void
    {
        $activeTask = DB::table('cleaning_tasks')
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->where('task_type', 'checkout_cleaning')
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)
            ->whereNull('deleted_at')
            ->whereNotIn('id', function ($query) {
                $query->select('cleaning_task_id')->from('housekeeping_checkout_turnover_intakes');
            })
            ->exists();

        if ($activeTask) {
            throw new DomainException(self::ERROR_ACTIVE_TASK_CONFLICT);
        }
    }

    private function resultFromIntake(HousekeepingCheckoutTurnoverIntake $intake, bool $replayed): HousekeepingCheckoutTurnoverConsumptionResult
    {
        $room = Room::withoutGlobalScopes()->findOrFail($intake->room_id);
        $handoff = FrontDeskCheckoutHousekeepingHandoff::withoutGlobalScopes()->findOrFail($intake->front_desk_checkout_housekeeping_handoff_id);
        $cleanliness = $room->cleanliness_status instanceof RoomCleanlinessStatusEnum
            ? $room->cleanliness_status->value
            : (string) $room->cleanliness_status;

        return new HousekeepingCheckoutTurnoverConsumptionResult(
            propertyId: $intake->property_id,
            handoffId: $intake->front_desk_checkout_housekeeping_handoff_id,
            intakeId: $intake->id,
            cleaningTaskId: $intake->cleaning_task_id,
            readinessTransitionId: $intake->room_readiness_transition_id,
            roomId: $intake->room_id,
            handoffDeliveryStatus: $handoff->delivery_status?->value ?? '',
            roomReadinessStatus: (string) $room->readiness_state,
            roomCleanlinessStatus: $cleanliness,
            replayed: $replayed,
            deliveryConfirmationPending: $handoff->delivery_status !== FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered,
            attempts: (int) $handoff->attempts,
        );
    }

    private function recordAudit(HousekeepingCheckoutTurnoverIntake $intake): void
    {
        AuditLog::record([
            'property_id' => $intake->property_id,
            'user_id' => null,
            'event' => 'housekeeping_checkout_turnover_intake_committed',
            'auditable_type' => HousekeepingCheckoutTurnoverIntake::class,
            'auditable_id' => $intake->id,
            'old_values' => [],
            'new_values' => [
                'property_id' => $intake->property_id,
                'handoff_id' => $intake->front_desk_checkout_housekeeping_handoff_id,
                'checkout_execution_id' => $intake->checkout_execution_id,
                'front_desk_stay_id' => $intake->front_desk_stay_id,
                'reservation_id' => $intake->reservation_id,
                'room_id' => $intake->room_id,
                'intake_id' => $intake->id,
                'cleaning_task_id' => $intake->cleaning_task_id,
                'readiness_transition_id' => $intake->room_readiness_transition_id,
                'business_date' => $intake->business_date?->format('Y-m-d'),
                'source_hash' => $intake->source_hash,
                'consumer_identity' => $intake->consumer_identity,
            ],
            'ip_address' => null,
            'user_agent' => null,
            'url' => null,
            'tags' => ['housekeeping-checkout-turnover', $intake->property_id, $intake->room_id],
        ]);
    }

    private function assertActiveProperty(string $propertyId): void
    {
        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->first();

        if (! $property) {
            throw new DomainException(self::ERROR_SOURCE_CONFLICT);
        }
    }

    private function handoffSourceHash(FrontDeskCheckoutExecution $execution, Carbon $occurredAt): string
    {
        return hash('sha256', json_encode([
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date?->format('Y-m-d'),
            'terminal_stay_status' => $execution->terminal_stay_status?->value,
            'execution_source_hash' => $execution->source_hash,
            'occurred_at' => $occurredAt->toDateTimeString(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function transitionSourceHash(
        string $propertyId,
        string $roomId,
        string $fromStatus,
        string $toStatus,
        string $idempotencyKey,
        string $handoffId,
    ): string {
        $payload = [
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::CheckoutTurnoverIntake->value,
            'reason' => null,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $handoffId,
            'idempotency_key' => $idempotencyKey,
        ];
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function intakeSourceHash(
        string $propertyId,
        string $handoffId,
        string $executionId,
        string $stayId,
        string $reservationId,
        string $roomId,
        string $handoffSourceHash,
        string $executionSourceHash,
        string $taskId,
        string $transitionId,
        string $beforeReadiness,
        string $beforeCleanliness,
    ): string {
        $payload = compact(
            'propertyId',
            'handoffId',
            'executionId',
            'stayId',
            'reservationId',
            'roomId',
            'handoffSourceHash',
            'executionSourceHash',
            'taskId',
            'transitionId',
            'beforeReadiness',
            'beforeCleanliness',
        );
        $payload['afterReadiness'] = 'waiting_cleaning';
        $payload['afterCleanliness'] = 'dirty';
        $payload['consumerIdentity'] = self::CONSUMER_IDENTITY;
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function safeFailureCode(DomainException $exception): string
    {
        $message = $exception->getMessage();

        return match ($message) {
            self::ERROR_SOURCE_CONFLICT => self::ERROR_SOURCE_CONFLICT,
            self::ERROR_ROOM_UNAVAILABLE => self::ERROR_ROOM_UNAVAILABLE,
            self::ERROR_ROOM_LIFECYCLE_CONFLICT => self::ERROR_ROOM_LIFECYCLE_CONFLICT,
            self::ERROR_ACTIVE_TASK_CONFLICT => self::ERROR_ACTIVE_TASK_CONFLICT,
            default => self::ERROR_INTERNAL_RETRYABLE_FAILURE,
        };
    }

    private function postgresWallClockUtc(): Carbon
    {
        $row = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc");

        return Carbon::parse($row->wall_clock_utc);
    }
}

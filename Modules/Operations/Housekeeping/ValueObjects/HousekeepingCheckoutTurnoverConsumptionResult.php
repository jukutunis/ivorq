<?php

namespace Modules\Operations\Housekeeping\ValueObjects;

final readonly class HousekeepingCheckoutTurnoverConsumptionResult
{
    public function __construct(
        public string $propertyId,
        public string $handoffId,
        public string $intakeId,
        public string $cleaningTaskId,
        public string $readinessTransitionId,
        public string $roomId,
        public string $handoffDeliveryStatus,
        public string $roomReadinessStatus,
        public string $roomCleanlinessStatus,
        public bool $replayed,
        public bool $deliveryConfirmationPending,
        public int $attempts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'handoff_id' => $this->handoffId,
            'intake_id' => $this->intakeId,
            'cleaning_task_id' => $this->cleaningTaskId,
            'readiness_transition_id' => $this->readinessTransitionId,
            'room_id' => $this->roomId,
            'handoff_delivery_status' => $this->handoffDeliveryStatus,
            'room_readiness_status' => $this->roomReadinessStatus,
            'room_cleanliness_status' => $this->roomCleanlinessStatus,
            'replayed' => $this->replayed,
            'delivery_confirmation_pending' => $this->deliveryConfirmationPending,
            'attempts' => $this->attempts,
        ];
    }
}

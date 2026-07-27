<?php

namespace Modules\Operations\FrontDesk\ValueObjects;

final readonly class FrontDeskCheckoutExecutionResult
{
    public function __construct(
        public string $propertyId,
        public string $frontDeskStayId,
        public string $reservationId,
        public string $checkoutExecutionId,
        public string $idempotencyKey,
        public string $terminalStatus,
        public string $businessDate,
        public string $occurredAt,
        public string $handoffId,
        public string $handoffDeliveryStatus,
        public bool $replayed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'front_desk_stay_id' => $this->frontDeskStayId,
            'reservation_id' => $this->reservationId,
            'checkout_execution_id' => $this->checkoutExecutionId,
            'idempotency_key' => $this->idempotencyKey,
            'terminal_status' => $this->terminalStatus,
            'business_date' => $this->businessDate,
            'occurred_at' => $this->occurredAt,
            'handoff_id' => $this->handoffId,
            'handoff_delivery_status' => $this->handoffDeliveryStatus,
            'replayed' => $this->replayed,
        ];
    }
}

<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Support\Carbon;

final readonly class CheckoutSensitiveConfirmationClaimResult
{
    public function __construct(
        public string $consumptionId,
        public string $issuanceId,
        public string $confirmationIdentity,
        public string $confirmationFingerprint,
        public string $actorId,
        public string $companyId,
        public string $propertyId,
        public string $frontDeskStayId,
        public string $checkoutIdempotencyKey,
        public Carbon $confirmedAt,
        public Carbon $expiresAt,
        public Carbon $consumedAt,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toEvidenceArray(): array
    {
        return [
            'consumption_id' => $this->consumptionId,
            'issuance_id' => $this->issuanceId,
            'confirmation_identity' => $this->confirmationIdentity,
            'confirmation_fingerprint' => $this->confirmationFingerprint,
            'actor_id' => $this->actorId,
            'company_id' => $this->companyId,
            'property_id' => $this->propertyId,
            'front_desk_stay_id' => $this->frontDeskStayId,
            'checkout_idempotency_key' => $this->checkoutIdempotencyKey,
            'confirmed_at' => $this->confirmedAt->toISOString(),
            'expires_at' => $this->expiresAt->toISOString(),
            'consumed_at' => $this->consumedAt->toISOString(),
        ];
    }
}

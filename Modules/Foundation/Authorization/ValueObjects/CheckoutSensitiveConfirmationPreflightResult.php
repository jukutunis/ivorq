<?php

namespace Modules\Foundation\Authorization\ValueObjects;

use Illuminate\Support\Carbon;

final readonly class CheckoutSensitiveConfirmationPreflightResult
{
    public function __construct(
        public string $issuanceId,
        public string $confirmationIdentity,
        public string $confirmationFingerprint,
        public string $actorId,
        public string $companyId,
        public string $propertyId,
        public string $frontDeskStayId,
        public string $checkoutIdempotencyKey,
        public string $sessionFingerprint,
        public Carbon $confirmedAt,
        public Carbon $expiresAt,
    ) {}
}

<?php

namespace Modules\Foundation\Authorization\Data;

use DateTimeImmutable;

final readonly class VerifiedFirstTrustConsumption
{
    public function __construct(
        public string $authorizationId,
        public string $authorityReference,
        public string $authorityIssuer,
        public string $executionId,
        public FirstTrustExpectedBinding $binding,
        public string $reservationReference,
        public DateTimeImmutable $reservedAt,
        public string $consumptionReference,
        public string $consumptionFingerprint,
        public DateTimeImmutable $consumedAt,
        public string $verifiedKeyId,
        public string $verifierBundleFingerprint,
    ) {}
}

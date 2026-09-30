<?php

namespace Modules\Foundation\Authorization\Data;

use DateTimeImmutable;

final readonly class VerifiedFirstTrustReservation
{
    public function __construct(
        public string $authorizationId,
        public string $authorityReference,
        public string $authorityIssuer,
        public string $executionId,
        public FirstTrustExpectedBinding $binding,
        public string $reservationReference,
        public string $reservationFingerprint,
        public DateTimeImmutable $reservedAt,
        public DateTimeImmutable $commitDeadline,
        public DateTimeImmutable $recoveryDeadline,
        public string $verifiedKeyId,
        public string $verifierBundleFingerprint,
    ) {}
}

<?php

namespace Modules\Foundation\Authorization\Data;

use DateTimeImmutable;

final readonly class VerifiedFirstTrustAuthorization
{
    public function __construct(
        public string $authorizationId,
        public string $authorityReference,
        public string $authorityIssuer,
        public string $authorityAudience,
        public FirstTrustExpectedBinding $binding,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $expiresAt,
        public string $verifiedKeyId,
        public string $verifierBundleFingerprint,
    ) {}
}

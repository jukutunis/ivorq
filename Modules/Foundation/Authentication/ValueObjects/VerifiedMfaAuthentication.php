<?php

namespace Modules\Foundation\Authentication\ValueObjects;

final readonly class VerifiedMfaAuthentication
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public string $propertyId,
        public string $challengeId,
    ) {}
}

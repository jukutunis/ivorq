<?php

namespace Modules\Foundation\Authorization\Data;

use InvalidArgumentException;

final readonly class FirstTrustExpectedBinding
{
    public function __construct(
        public string $environment,
        public string $installationId,
        public string $requestFingerprint,
        public string $canonicalSha,
    ) {
        if (! in_array($environment, ['OPERATIONAL', 'REHEARSAL'], true)) {
            throw new InvalidArgumentException('FIRST_TRUST_BINDING_ENVIRONMENT_INVALID');
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,199}\z/', $installationId) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_BINDING_INSTALLATION_INVALID');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $requestFingerprint) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_BINDING_REQUEST_FINGERPRINT_INVALID');
        }

        if (preg_match('/\A[a-f0-9]{40}\z/', $canonicalSha) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_BINDING_CANONICAL_SHA_INVALID');
        }
    }
}

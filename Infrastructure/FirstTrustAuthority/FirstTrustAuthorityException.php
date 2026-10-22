<?php

namespace Infrastructure\FirstTrustAuthority;

use RuntimeException;

final class FirstTrustAuthorityException extends RuntimeException
{
    private const ALLOWED = [
        'AUTHORITY_UNAVAILABLE', 'AUTHORITY_CREDENTIAL_REJECTED',
        'AUTHORITY_PROTOCOL_UNSUPPORTED', 'AUTHORIZATION_INVALID',
        'AUTHORIZATION_EXPIRED', 'AUTHORIZATION_REVOKED',
        'ENVIRONMENT_MISMATCH', 'INSTALLATION_MISMATCH',
        'CANONICAL_SHA_MISMATCH', 'REQUEST_FINGERPRINT_MISMATCH',
        'ALREADY_RESERVED_OTHER_EXECUTION', 'RESERVATION_EXPIRED',
        'ALREADY_CONSUMED_OTHER_EXECUTION', 'RECEIPT_INVALID',
        'AUTHORITY_RECOVERY_REQUIRED', 'AUTHORITY_STATE_UNAVAILABLE',
        'AUTHORITY_STATE_INVALID', 'AUTHORITY_STATE_BINDING_MISMATCH',
    ];

    public function __construct(string $stableCode)
    {
        parent::__construct(in_array($stableCode, self::ALLOWED, true) ? $stableCode : 'AUTHORITY_UNAVAILABLE');
    }
}

<?php

namespace Modules\Foundation\Authorization\Data;

use InvalidArgumentException;

final readonly class FirstTrustResult
{
    public function __construct(
        public string $runId,
        public string $executionId,
        public string $state,
        public string $userId,
        public string $companyId,
        public string $propertyId,
        public string $ownerActivationId,
        public ?string $completionEvidenceFingerprint,
        public string $activationDeliveryState,
    ) {
        if (! in_array($activationDeliveryState, ['sent', 'failed_recovery_available', 'not_attempted'], true)) {
            throw new InvalidArgumentException('FIRST_TRUST_DELIVERY_STATE_INVALID');
        }
    }
}

<?php

namespace Modules\Foundation\Authorization\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class VerifiedFirstTrustAuthorityState
{
    public function __construct(
        public string $authorizationId,
        public string $authorityReference,
        public string $authorityIssuer,
        public string $state,
        public FirstTrustExpectedBinding $binding,
        public DateTimeImmutable $stateGeneratedAt,
        public string $verifiedKeyId,
        public string $verifierBundleFingerprint,
        public ?string $executionId = null,
        public ?string $reservationReference = null,
        public ?string $reservationFingerprint = null,
        public ?DateTimeImmutable $reservedAt = null,
        public ?DateTimeImmutable $commitDeadline = null,
        public ?DateTimeImmutable $recoveryDeadline = null,
        public ?string $consumptionReference = null,
        public ?string $consumptionFingerprint = null,
        public ?DateTimeImmutable $consumedAt = null,
    ) {
        if (! in_array($state, ['AVAILABLE', 'RESERVED', 'CONSUMED', 'REVOKED', 'EXPIRED'], true)) {
            throw new InvalidArgumentException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }

        $reservation = [
            $executionId,
            $reservationReference,
            $reservationFingerprint,
            $reservedAt,
            $commitDeadline,
            $recoveryDeadline,
        ];
        $consumption = [$consumptionReference, $consumptionFingerprint, $consumedAt];

        if ($state === 'RESERVED' && (! $this->allPresent($reservation) || ! $this->allAbsent($consumption))) {
            throw new InvalidArgumentException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }

        if ($state === 'CONSUMED' && (! $this->allPresent($reservation) || ! $this->allPresent($consumption))) {
            throw new InvalidArgumentException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }

        if (in_array($state, ['AVAILABLE', 'REVOKED', 'EXPIRED'], true)
            && (! $this->allAbsent($reservation) || ! $this->allAbsent($consumption))) {
            throw new InvalidArgumentException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }
    }

    private function allPresent(array $values): bool
    {
        return count(array_filter($values, static fn (mixed $value): bool => $value !== null)) === count($values);
    }

    private function allAbsent(array $values): bool
    {
        return count(array_filter($values, static fn (mixed $value): bool => $value !== null)) === 0;
    }
}

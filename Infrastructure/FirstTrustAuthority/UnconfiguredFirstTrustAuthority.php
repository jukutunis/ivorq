<?php

namespace Infrastructure\FirstTrustAuthority;

use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorityState;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorization;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustReservation;
use RuntimeException;
use SensitiveParameter;

final class UnconfiguredFirstTrustAuthority implements FirstTrustAuthority
{
    public function verifyAuthorization(
        #[SensitiveParameter] string $serializedEnvelope,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorization {
        $this->failClosed();
    }

    public function state(
        string $authorizationId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorityState {
        $this->failClosed();
    }

    public function reserve(
        #[SensitiveParameter] string $serializedAuthorization,
        string $executionId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustReservation {
        $this->failClosed();
    }

    public function consume(
        string $authorizationId,
        string $executionId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustConsumption {
        $this->failClosed();
    }

    private function failClosed(): never
    {
        throw new RuntimeException('FIRST_TRUST_AUTHORITY_UNCONFIGURED');
    }
}

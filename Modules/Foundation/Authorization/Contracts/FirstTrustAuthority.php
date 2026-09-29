<?php

namespace Modules\Foundation\Authorization\Contracts;

use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorityState;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorization;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustReservation;
use SensitiveParameter;

interface FirstTrustAuthority
{
    public function verifyAuthorization(
        #[SensitiveParameter] string $serializedEnvelope,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorization;

    public function state(
        string $authorizationId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorityState;

    public function reserve(
        #[SensitiveParameter] string $serializedAuthorization,
        string $executionId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustReservation;

    public function consume(
        string $authorizationId,
        string $executionId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustConsumption;
}

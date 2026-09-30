<?php

namespace Tests\Unit\Infrastructure\FirstTrustAuthority;

use Infrastructure\FirstTrustAuthority\UnconfiguredFirstTrustAuthority;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use RuntimeException;
use Tests\TestCase;

class UnconfiguredFirstTrustAuthorityTest extends TestCase
{
    public function test_every_authority_operation_fails_closed(): void
    {
        $authority = app(FirstTrustAuthority::class);
        $this->assertInstanceOf(UnconfiguredFirstTrustAuthority::class, $authority);
        $binding = new FirstTrustExpectedBinding(
            environment: 'OPERATIONAL',
            installationId: 'installation/alpha-01',
            requestFingerprint: str_repeat('a', 64),
            canonicalSha: str_repeat('b', 40),
        );

        $operations = [
            fn () => $authority->verifyAuthorization('not-a-real-envelope', $binding),
            fn () => $authority->state('authorization/test', $binding),
            fn () => $authority->reserve('not-a-real-envelope', 'execution/test', $binding),
            fn () => $authority->consume('authorization/test', 'execution/test', $binding),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Unconfigured authority must never return a successful state.');
            } catch (RuntimeException $exception) {
                $this->assertSame('FIRST_TRUST_AUTHORITY_UNCONFIGURED', $exception->getMessage());
            }
        }
    }
}

<?php

namespace Tests\Unit\Foundation\Authorization;

use InvalidArgumentException;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FirstTrustExpectedBindingTest extends TestCase
{
    #[DataProvider('environments')]
    public function test_valid_environment_and_100_character_installation_id_are_accepted(string $environment): void
    {
        $binding = new FirstTrustExpectedBinding(
            $environment,
            str_repeat('i', 100),
            str_repeat('a', 64),
            str_repeat('b', 40),
        );

        $this->assertSame($environment, $binding->environment);
        $this->assertSame(100, strlen($binding->installationId));
    }

    public function test_101_character_installation_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FIRST_TRUST_BINDING_INSTALLATION_INVALID');

        new FirstTrustExpectedBinding('OPERATIONAL', str_repeat('i', 101), str_repeat('a', 64), str_repeat('b', 40));
    }

    public function test_malformed_request_fingerprint_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FIRST_TRUST_BINDING_REQUEST_FINGERPRINT_INVALID');

        new FirstTrustExpectedBinding('OPERATIONAL', 'installation-1', str_repeat('g', 64), str_repeat('b', 40));
    }

    public function test_malformed_canonical_sha_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FIRST_TRUST_BINDING_CANONICAL_SHA_INVALID');

        new FirstTrustExpectedBinding('REHEARSAL', 'installation-1', str_repeat('a', 64), str_repeat('B', 40));
    }

    public static function environments(): array
    {
        return [
            'operational' => ['OPERATIONAL'],
            'rehearsal' => ['REHEARSAL'],
        ];
    }
}

<?php

namespace Tests\Unit\Infrastructure\FirstTrustAuthority;

final class FirstTrustTestFactory
{
    public const KEY_ID = 'arn:aws:kms:ap-southeast-1:123456789012:key/11111111-1111-4111-8111-111111111111';

    public const ROTATED_KEY_ID = 'arn:aws:kms:ap-southeast-1:123456789012:key/22222222-2222-4222-8222-222222222222';

    public static function fixture(string $name): string
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/first-trust-authority/v1/'.$name));
        if (! is_string($bytes)) {
            throw new \RuntimeException('Missing first-trust fixture: '.$name);
        }

        return $bytes;
    }

    /** @return array<string,mixed> */
    public static function fixtureJson(string $name): array
    {
        return json_decode(self::fixture($name), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    public static function payload(string $serialized): array
    {
        $envelope = json_decode($serialized, true, 8, JSON_THROW_ON_ERROR);
        $encoded = strtr($envelope['payload'], '-_', '+/');
        $decoded = base64_decode($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
    }

    public static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::canonical(...), $value)).']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $entry) {
                $parts[] = self::canonical((string) $key).':'.self::canonical($entry);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

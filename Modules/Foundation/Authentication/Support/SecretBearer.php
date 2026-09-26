<?php

namespace Modules\Foundation\Authentication\Support;

use InvalidArgumentException;

final class SecretBearer
{
    public static function generate(): array
    {
        $bytes = random_bytes(32);

        return [self::encode($bytes), $bytes];
    }

    public static function decode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1 || str_contains($value, '=')) {
            throw new InvalidArgumentException('Invalid bearer encoding.');
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
        if ($decoded === false || strlen($decoded) !== 32 || ! hash_equals($value, self::encode($decoded))) {
            throw new InvalidArgumentException('Invalid bearer encoding.');
        }

        return $decoded;
    }

    public static function digest(string $domain, string $bytes): string
    {
        return hash('sha256', $domain."\0".$bytes);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

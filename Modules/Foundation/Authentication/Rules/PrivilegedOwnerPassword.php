<?php

namespace Modules\Foundation\Authentication\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PrivilegedOwnerPassword implements ValidationRule
{
    private const COMMON = [
        'passwordpassword',
        'password123456',
        '123456789012345',
        'qwertyuiopasdfg',
        'letmeinletmein',
        'adminadminadmin',
        'changemechangeme',
        'welcome12345678',
        'ivorqivorqivorq',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 15 || mb_strlen($value) > 128) {
            $fail('The :attribute must be between 15 and 128 characters.');

            return;
        }

        if (in_array(mb_strtolower(trim($value)), self::COMMON, true)) {
            $fail('The :attribute is too common. Choose a less predictable passphrase.');
        }
    }
}

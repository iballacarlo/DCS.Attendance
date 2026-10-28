<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordByteLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt only considers the first 72 bytes, including multibyte characters.
        if (is_string($value) && strlen($value) > 72) {
            $fail('The password must not exceed 72 bytes. Use a shorter passphrase.');
        }
    }
}

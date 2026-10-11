<?php

namespace App\Rules;

use App\Support\E164PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * A real phone number, read in the default region when written without a country code.
 */
class ValidPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalised = is_string($value) ? E164PhoneNumber::tryNormalize($value) : null;

        if ($normalised === null || ! (new PhoneNumber($normalised))->isValid()) {
            $fail(__('The :attribute must be a valid phone number.'));
        }
    }
}

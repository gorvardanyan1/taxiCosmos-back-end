<?php

namespace App\Rules;

use App\Services\Zones\ZonePolygonValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * Validation rule for the `polygon` field of a zone request (GeoJSON, ST_IsValid).
 */
class ZonePolygon implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(ZonePolygonValidator::class)->validate($value);
        } catch (ValidationException $e) {
            $fail($e->validator->errors()->first('polygon'));
        }
    }
}

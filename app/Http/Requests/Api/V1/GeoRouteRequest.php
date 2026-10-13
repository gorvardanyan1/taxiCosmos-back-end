<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/geo/route?from=lat,lng&to=lat,lng
 */
class GeoRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['from' => ['required', 'string', $this->point()], 'to' => ['required', 'string', $this->point()]];
    }

    public function origin(): GeoPoint
    {
        return self::parse((string) $this->validated('from')) ?? throw new \LogicException('Validated point did not parse.');
    }

    public function destination(): GeoPoint
    {
        return self::parse((string) $this->validated('to')) ?? throw new \LogicException('Validated point did not parse.');
    }

    /**
     * "lat,lng" in decimal degrees, within range.
     */
    private function point(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || self::parse($value) === null) {
                $fail(__('The :attribute must be a point written as lat,lng.', ['attribute' => $attribute]));
            }
        };
    }

    private static function parse(string $value): ?GeoPoint
    {
        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', trim($value), $m) !== 1) {
            return null;
        }

        return abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180 ? new GeoPoint((float) $m[1], (float) $m[2]) : null;
    }
}

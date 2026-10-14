<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/driver/location: one position of the signed-in driver. Nothing here names a driver.
 */
class DriverLocationRequest extends FormRequest
{
    /** Redis GEO refuses latitudes beyond the Web Mercator limit, so the range is that, not 90. */
    private const MAX_LATITUDE = 85.05112878;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-'.self::MAX_LATITUDE.','.self::MAX_LATITUDE],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            // Degrees clockwise from north; 360 is north again.
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            // Metres per second (a car never needs more than 100), and the GPS accuracy radius in metres.
            'speed' => ['nullable', 'numeric', 'between:0,100'],
            'accuracy' => ['nullable', 'numeric', 'between:0,10000'],
        ];
    }

    public function point(): GeoPoint
    {
        return new GeoPoint((float) $this->validated('lat'), (float) $this->validated('lng'));
    }

    public function heading(): ?int
    {
        return $this->filled('heading') ? ((int) round((float) $this->validated('heading'))) % 360 : null;
    }

    public function speed(): ?float
    {
        return $this->filled('speed') ? (float) $this->validated('speed') : null;
    }

    public function accuracy(): ?float
    {
        return $this->filled('accuracy') ? (float) $this->validated('accuracy') : null;
    }
}

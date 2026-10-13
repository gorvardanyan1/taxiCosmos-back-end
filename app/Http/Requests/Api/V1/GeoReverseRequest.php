<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/geo/reverse?lat=&lng=
 */
class GeoReverseRequest extends FormRequest
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
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function point(): GeoPoint
    {
        return new GeoPoint((float) $this->validated('lat'), (float) $this->validated('lng'));
    }
}

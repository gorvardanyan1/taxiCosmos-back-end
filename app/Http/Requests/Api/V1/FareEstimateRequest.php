<?php

namespace App\Http\Requests\Api\V1;

use App\Data\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class FareEstimateRequest extends FormRequest
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
            'pickup' => ['required', 'array:lat,lng'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff' => ['required', 'array:lat,lng'],
            'dropoff.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function pickup(): GeoPoint
    {
        return new GeoPoint((float) $this->validated('pickup.lat'), (float) $this->validated('pickup.lng'));
    }

    public function dropoff(): GeoPoint
    {
        return new GeoPoint((float) $this->validated('dropoff.lat'), (float) $this->validated('dropoff.lng'));
    }
}

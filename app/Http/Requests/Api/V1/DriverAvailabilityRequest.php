<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DriverAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/driver/availability {availability: online|offline}. "on_trip" is set by the trip flow, never by the driver.
 */
class DriverAvailabilityRequest extends FormRequest
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
        return ['availability' => ['required', 'string', Rule::in([DriverAvailability::Online->value, DriverAvailability::Offline->value])]];
    }

    public function target(): DriverAvailability
    {
        return DriverAvailability::from($this->validated('availability'));
    }
}

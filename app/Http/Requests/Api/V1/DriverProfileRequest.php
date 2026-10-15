<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Zone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/v1/driver/profile: the licence number and expiry, and the home zone. */
class DriverProfileRequest extends FormRequest
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
            'license_number' => ['required', 'string', 'min:4', 'max:32'],
            'license_expiry' => ['required', 'date_format:Y-m-d', 'after:today'],
            'home_zone_id' => ['nullable', 'integer', Rule::exists(Zone::class, 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array{license_number: string, license_expiry: string, home_zone_id: ?int}
     */
    public function profile(): array
    {
        return [
            'license_number' => trim($this->validated('license_number')),
            'license_expiry' => $this->validated('license_expiry'),
            'home_zone_id' => $this->validated('home_zone_id'),
        ];
    }
}

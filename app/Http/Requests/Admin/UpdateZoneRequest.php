<?php

namespace App\Http\Requests\Admin;

use App\Rules\ZonePolygon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (zones.manage).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the fields sent change. The code is fixed once created (fares, surge and reports refer to it).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'currency' => ['sometimes', 'required', Rule::in(array_column(config('taxikosmos.currencies'), 'code'))],
            'priority' => ['sometimes', 'required', 'integer', 'between:0,1000'],
            'polygon' => ['sometimes', 'required', new ZonePolygon],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.prohibited' => 'The zone code cannot be changed.'];
    }
}

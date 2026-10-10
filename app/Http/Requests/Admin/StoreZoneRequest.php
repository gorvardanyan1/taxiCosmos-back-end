<?php

namespace App\Http\Requests\Admin;

use App\Rules\ZonePolygon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZoneRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (zones.manage).
     */
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
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,31}$/', Rule::unique('zones', 'code')],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in(array_column(config('taxikosmos.currencies'), 'code'))],
            'priority' => ['nullable', 'integer', 'between:0,1000'],
            'polygon' => ['required', new ZonePolygon],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => 'The code must be 2-32 characters: capital letters, digits, "-" or "_".'];
    }
}

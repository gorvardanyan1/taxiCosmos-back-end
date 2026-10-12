<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleClass;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds a vehicle (POST: every field) or changes one (PATCH: only the fields sent). The plate is stored
 * in capitals without spaces and belongs to one vehicle at a time.
 */
class SaveVehicleRequest extends FormRequest
{
    /**
     * Authorization is the route's permission middleware (drivers.edit).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('plate_number'))) {
            $this->merge(['plate_number' => mb_strtoupper((string) preg_replace('/\s+/u', '', $this->input('plate_number')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $vehicle = (int) $this->route('vehicle');

        return [
            'make' => [$required, 'string', 'max:64'],
            'model' => [$required, 'string', 'max:64'],
            'year' => [$required, 'integer', 'between:1980,'.((int) now()->year + 1)],
            'color' => [$required, 'string', 'max:32'],
            'plate_number' => [$required, 'string', 'max:20', Rule::unique(Vehicle::class, 'plate_number')->whereNull('deleted_at')->ignore($vehicle)],
            'vehicle_class' => [$required, Rule::enum(VehicleClass::class)],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['plate_number.unique' => __('Another vehicle already has this plate number.')];
    }
}

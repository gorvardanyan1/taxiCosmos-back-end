<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST and PATCH /api/v1/driver/vehicles. Adding needs every field; changing sends only what changes. The plate is stored the way
 * it is compared: upper case, without spaces.
 */
class DriverVehicleRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'make' => [$presence, 'string', 'min:1', 'max:64'],
            'model' => [$presence, 'string', 'min:1', 'max:64'],
            'year' => [$presence, 'integer', 'min:1980', 'max:'.((int) date('Y') + 1)],
            'color' => [$presence, 'string', 'min:1', 'max:32'],
            'plate_number' => [$presence, 'string', 'min:2', 'max:20'],
            'vehicle_class' => [$presence, Rule::enum(VehicleClass::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function vehicle(): array
    {
        $data = $this->validated();

        if (isset($data['plate_number'])) {
            $data['plate_number'] = mb_strtoupper(preg_replace('/\s+/', '', $data['plate_number']));
        }

        foreach (['make', 'model', 'color'] as $field) {
            isset($data[$field]) && $data[$field] = trim($data[$field]);
        }

        return $data;
    }
}

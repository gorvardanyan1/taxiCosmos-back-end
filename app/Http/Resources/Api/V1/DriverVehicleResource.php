<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehicle
 */
class DriverVehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'plate_number' => $this->plate_number,
            'color' => $this->color,
            'vehicle_class' => $this->vehicle_class->value,
            'status' => $this->status->value,
            'is_primary' => $this->is_primary,
        ];
    }
}

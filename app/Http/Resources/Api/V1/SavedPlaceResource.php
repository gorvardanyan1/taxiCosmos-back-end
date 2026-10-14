<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SavedPlace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedPlace
 */
class SavedPlaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label->value,
            'name' => $this->name,
            'address' => $this->address,
            'lat' => $this->location->latitude,
            'lng' => $this->location->longitude,
        ];
    }
}

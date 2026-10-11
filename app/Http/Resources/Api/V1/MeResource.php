<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as the apps see it (the /me shape; P3-T5 extends it with profile fields).
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'is_rider' => $this->is_rider,
            'is_driver' => $this->is_driver,
            'status' => $this->status->value,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The driver's own application as the driver app shows it. The licence number is only ever shown masked; the status is the same
 * one GET /api/v1/me carries.
 *
 * @mixin DriverProfile
 */
class DriverApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'verification_status' => $this->verification_status->value,
            'license' => [
                'number_last4' => $this->license_number === null ? null : substr($this->license_number, -4),
                'expiry' => $this->license_expiry?->toDateString(),
            ],
            'home_zone_id' => $this->home_zone_id,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved card as the rider app shows it ("Visa •••• 4242, 04/28"). The gateway's ids never leave the server.
 *
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = now();

        return [
            'id' => $this->id,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'is_default' => $this->is_default,
            'is_expired' => $this->exp_year < $now->year || ($this->exp_year === $now->year && $this->exp_month < $now->month),
        ];
    }
}

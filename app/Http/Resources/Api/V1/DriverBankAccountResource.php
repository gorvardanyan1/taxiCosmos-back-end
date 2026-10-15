<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DriverBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout account as its owner sees it: masked. The full number is never sent back.
 *
 * @mixin DriverBankAccount
 */
class DriverBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_holder' => $this->account_holder,
            'bank_name' => $this->bank_name,
            'account_last4' => $this->account_last4,
            'swift' => $this->swift,
            'is_default' => $this->is_default,
            'verified' => $this->verified_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

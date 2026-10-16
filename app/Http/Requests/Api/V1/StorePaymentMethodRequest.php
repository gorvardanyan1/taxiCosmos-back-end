<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/payment-methods: the token the gateway's own card form (Stripe PaymentSheet, Authorize.Net Accept.js) gave the
 * app. A card number is never accepted here.
 */
class StorePaymentMethodRequest extends FormRequest
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
        return [
            'token' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_\-]{2,190}$/'],
            'make_default' => ['sometimes', 'boolean'],
        ];
    }
}

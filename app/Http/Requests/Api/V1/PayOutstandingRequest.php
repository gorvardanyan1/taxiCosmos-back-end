<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/account/outstanding/pay. The Idempotency-Key header is required: the app makes one per tap of "Pay" and resends it
 * on a retry, so a flaky connection charges once. Without payment_method_id the default card pays.
 */
class PayOutstandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{8,64}$/'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['idempotency_key.required' => __('Send an Idempotency-Key header with this payment.')];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/support-tickets/{id}/messages: the requester writes on their own ticket (Idempotency-Key optional). */
class StoreSupportMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_\-]{8,64}$/'],
        ];
    }
}

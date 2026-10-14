<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SupportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/support-tickets. The requester is the signed-in person and the app comes from the token, never from the body.
 * The optional Idempotency-Key header makes a retry safe (the same key never opens a second ticket).
 */
class StoreSupportTicketRequest extends FormRequest
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
            'category' => ['required', Rule::enum(SupportCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'trip_id' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_\-]{8,64}$/'],
        ];
    }

    public function category(): SupportCategory
    {
        return SupportCategory::from($this->validated('category'));
    }

    public function idempotencyKey(): ?string
    {
        return $this->validated('idempotency_key');
    }
}

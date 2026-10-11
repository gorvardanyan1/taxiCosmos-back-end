<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SocialLoginRequest extends FormRequest
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
            // Google/Apple: the ID token (a JWT). Facebook: the access token.
            'token' => ['required', 'string', 'max:4096'],
            'app' => ['required', Rule::enum(AppClient::class)],
            'device_id' => ['required', 'string', 'min:8', 'max:100'],
            'locale' => ['nullable', 'string', Rule::in(config('taxikosmos.locales'))],
        ];
    }
}

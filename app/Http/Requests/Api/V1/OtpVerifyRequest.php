<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppClient;
use App\Rules\ValidPhoneNumber;
use App\Support\E164PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OtpVerifyRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:32', new ValidPhoneNumber],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'app' => ['required', Rule::enum(AppClient::class)],
            'device_id' => ['required', 'string', 'min:8', 'max:100'],
            'locale' => ['nullable', 'string', Rule::in(config('taxikosmos.locales'))],
        ];
    }

    public function phone(): string
    {
        return E164PhoneNumber::normalize($this->validated('phone'));
    }
}

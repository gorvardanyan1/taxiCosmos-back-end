<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\ValidPhoneNumber;
use App\Support\E164PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class PhoneVerifyRequest extends FormRequest
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
        ];
    }

    public function phone(): string
    {
        return E164PhoneNumber::normalize($this->validated('phone'));
    }
}

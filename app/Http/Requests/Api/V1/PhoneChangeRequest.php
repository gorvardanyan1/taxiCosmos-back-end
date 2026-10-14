<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\ValidPhoneNumber;
use App\Support\E164PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/me/phone/request: the new number a code is sent to.
 */
class PhoneChangeRequest extends FormRequest
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
        return ['phone' => ['required', 'string', 'max:32', new ValidPhoneNumber]];
    }

    public function phone(): string
    {
        return E164PhoneNumber::normalize($this->validated('phone'));
    }
}

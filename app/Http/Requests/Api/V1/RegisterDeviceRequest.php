<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DevicePlatform;
use App\Support\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/devices: the Expo push token of this phone, sent by the app on sign-in and on every token refresh.
 */
class RegisterDeviceRequest extends FormRequest
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
            // ExponentPushToken[...] (or ExpoPushToken[...]): anything else is not a token Expo can deliver to.
            'push_token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[A-Za-z0-9_-]{8,200}\]$/'],
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
            'locale' => ['nullable', 'string', Rule::in(Locales::supported())],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}

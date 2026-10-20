<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppClient;
use App\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/app-config?app=rider|driver&platform=ios|android&version=1.2.3
 *
 * `version` is deliberately not validated beyond its length: a missing or malformed one is not a request error, it is an
 * unsupported app, and the answer says `force_update: true`.
 */
class AppConfigRequest extends FormRequest
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
            'app' => ['required', 'string', Rule::enum(AppClient::class)],
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
            'version' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function app(): AppClient
    {
        return AppClient::from($this->validated('app'));
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from($this->validated('platform'));
    }

    public function version(): ?string
    {
        $version = $this->validated('version');

        return is_string($version) ? $version : null;
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/internal/socket-auth: exactly one of a mobile access token or an admin socket ticket. The caller is already
 * authenticated by the service-token middleware.
 */
class SocketAuthRequest extends FormRequest
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
            'token' => ['required_without:ticket', 'prohibits:ticket', 'string', 'max:200'],
            'ticket' => ['required_without:token', 'prohibits:token', 'string', 'max:200'],
        ];
    }
}

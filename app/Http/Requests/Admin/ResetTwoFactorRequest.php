<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A super admin resets another admin's two-factor authentication: their own password, and why. Authorisation is the
 * route's permission middleware (admins.manage); the service also insists on a super admin.
 */
class ResetTwoFactorRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}

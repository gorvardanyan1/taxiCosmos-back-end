<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An action on the signed-in admin's own two-factor authentication: the account password again, so a stolen session
 * cannot change it. Authorisation is the route middleware (a signed-in admin).
 */
class PasswordConfirmationRequest extends FormRequest
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
        return ['password' => ['required', 'string', 'max:255']];
    }
}

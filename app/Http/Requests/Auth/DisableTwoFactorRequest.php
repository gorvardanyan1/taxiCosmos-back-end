<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Switching two-factor authentication off. A role that must have it is refused before anything else is looked at, whatever
 * is sent (AdminTwoFactorService enforces the same rule again).
 */
class DisableTwoFactorRequest extends PasswordConfirmationRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ! ($user instanceof User && $user->requiresTwoFactor());
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__('Your role requires two-factor authentication, so it cannot be switched off.'));
    }
}

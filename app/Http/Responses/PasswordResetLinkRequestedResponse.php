<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forgot-password answer that never reveals whether an email belongs to an admin: an unknown
 * (or non-admin) email gets the same "check your inbox" message as a real one. Only the
 * per-email throttle is reported as an error.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request): Response
    {
        if ($this->status === Password::INVALID_USER) {
            return back()->with('status', trans(Password::RESET_LINK_SENT));
        }

        throw ValidationException::withMessages(['email' => [trans($this->status)]]);
    }
}

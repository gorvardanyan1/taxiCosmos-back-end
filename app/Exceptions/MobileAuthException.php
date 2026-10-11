<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused mobile sign-in or token operation. Renders itself as the JSON error the apps switch on.
 * Messages never say which of "no such account" / "wrong code" applied, so they cannot be used to
 * discover who has an account.
 */
class MobileAuthException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self('The code is invalid or has expired.', 422, 'code');
    }

    public static function invalidRefreshToken(): self
    {
        return new self('The session has ended. Please sign in again.', 401);
    }

    public static function invalidSocialToken(): self
    {
        return new self('The sign-in token could not be verified.', 401);
    }

    public static function accountSuspended(): self
    {
        return new self('This account is suspended.', 403);
    }

    public static function accountUnavailable(): self
    {
        return new self('This account is not available.', 403);
    }

    public static function adminAccount(): self
    {
        return new self('Admin accounts cannot sign in to the apps.', 403);
    }

    public static function phoneTaken(): self
    {
        return new self('This phone number belongs to another account.', 409, 'phone');
    }

    public static function phoneAlreadySet(): self
    {
        return new self('This account already has a phone number.', 409, 'phone');
    }

    public static function notPendingPhone(): self
    {
        return new self('This session does not need a phone number.', 409);
    }

    public static function smsUnavailable(): self
    {
        return new self('The code could not be sent. Please try again shortly.', 503);
    }

    public static function providerUnavailable(): self
    {
        return new self('Sign-in with this provider is not available right now.', 503);
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage()];

        if ($this->field !== null) {
            $body['errors'] = [$this->field => [$this->getMessage()]];
        }

        return response()->json($body, $this->status);
    }
}

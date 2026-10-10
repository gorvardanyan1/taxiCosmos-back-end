<?php

namespace App\Exceptions;

/**
 * An action on a rider that their current state (or who they are) does not allow (HTTP 409).
 */
class RiderStateException extends ConflictException
{
    public static function notActive(): self
    {
        return new self('Only active riders can be suspended.');
    }

    public static function notSuspended(): self
    {
        return new self('Only suspended riders can be reactivated.');
    }

    public static function adminAccount(): self
    {
        return new self('This account has admin access. Admin accounts are managed under Settings → Users.');
    }

    public static function ownAccount(): self
    {
        return new self('You cannot suspend your own account.');
    }
}

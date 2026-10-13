<?php

namespace App\Exceptions;

/**
 * A two-factor action the account's current state does not allow (HTTP 409).
 */
class TwoFactorStateException extends ConflictException
{
    public static function alreadyEnabled(): self
    {
        return new self(__('Two-factor authentication is already on.'));
    }

    public static function notStarted(): self
    {
        return new self(__('Start the two-factor setup first.'));
    }

    public static function notEnabled(): self
    {
        return new self(__('Two-factor authentication is not on for this account.'));
    }

    public static function resetOwnAccount(): self
    {
        return new self(__('You cannot reset your own two-factor authentication here. Use My Account.'));
    }

    public static function notAnAdmin(): self
    {
        return new self(__('Only admin accounts have two-factor authentication to reset.'));
    }
}

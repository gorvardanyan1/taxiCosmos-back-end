<?php

namespace App\Exceptions;

/** A wallet movement its current state does not allow (HTTP 409). */
class WalletException extends ConflictException
{
    public static function insufficientBalance(): self
    {
        return new self(__('The wallet balance is not enough.'));
    }

    public static function overflow(): self
    {
        return new self(__('The wallet balance would be too large.'));
    }
}

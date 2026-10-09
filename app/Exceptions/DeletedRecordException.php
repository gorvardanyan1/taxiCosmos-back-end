<?php

namespace App\Exceptions;

use DomainException;

/**
 * An action was attempted on a soft-deleted record (e.g. making a removed vehicle primary).
 */
class DeletedRecordException extends DomainException
{
    public static function for(string $what): self
    {
        return new self("Cannot use a deleted {$what}.");
    }
}

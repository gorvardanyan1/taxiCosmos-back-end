<?php

namespace App\Exceptions;

class ZoneStateException extends ConflictException
{
    public static function alreadyActive(): self
    {
        return new self('This zone is already active.');
    }

    public static function alreadyInactive(): self
    {
        return new self('This zone is already inactive.');
    }
}

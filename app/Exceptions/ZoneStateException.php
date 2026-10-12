<?php

namespace App\Exceptions;

class ZoneStateException extends ConflictException
{
    public static function alreadyActive(): self
    {
        return new self(__('This zone is already active.'));
    }

    public static function currencyInUse(): self
    {
        return new self(__('The currency cannot change once the zone has fare rules, commission rules, fees or trips.'));
    }

    public static function alreadyInactive(): self
    {
        return new self(__('This zone is already inactive.'));
    }
}

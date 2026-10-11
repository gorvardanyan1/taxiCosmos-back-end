<?php

namespace App\Exceptions;

use App\Enums\TripCancelledBy;
use App\Enums\TripStatus;

/**
 * A change to a trip that its current state does not allow (HTTP 409).
 */
class TripStateException extends ConflictException
{
    public static function invalidTransition(TripStatus $from, TripStatus $to): self
    {
        return new self("A trip cannot move from {$from->value} to {$to->value}.");
    }

    public static function cannotCancel(TripStatus $from, TripCancelledBy $by): self
    {
        return new self("A trip that is {$from->value} cannot be cancelled by the {$by->value}.");
    }

    public static function cancellationNeedsActor(): self
    {
        return new self('A cancelled trip needs to say who cancelled it.');
    }

    public static function pricingFrozen(): self
    {
        return new self('The pricing of a finished trip cannot be changed.');
    }
}

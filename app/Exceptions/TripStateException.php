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
        return new self(__('A trip cannot move from :from to :to.', ['from' => $from->value, 'to' => $to->value]));
    }

    public static function cannotCancel(TripStatus $from, TripCancelledBy $by): self
    {
        return new self(__('A trip that is :status cannot be cancelled by the :actor.', ['status' => $from->value, 'actor' => $by->value]));
    }

    public static function cancellationNeedsActor(): self
    {
        return new self(__('A cancelled trip needs to say who cancelled it.'));
    }

    public static function pricingFrozen(): self
    {
        return new self(__('The pricing of a finished trip cannot be changed.'));
    }
}

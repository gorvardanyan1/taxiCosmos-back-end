<?php

namespace App\Enums;

/**
 * Which mobile app is calling. It is also the Sanctum ability of the access token, and picks the
 * capability flag set on the account at first sign-in.
 */
enum AppClient: string
{
    case Rider = 'rider';
    case Driver = 'driver';

    public function capabilityColumn(): string
    {
        return match ($this) {
            self::Rider => 'is_rider',
            self::Driver => 'is_driver',
        };
    }

    /**
     * The ability of a session that still has to collect a phone number (social sign-in without one).
     */
    public function pendingAbility(): string
    {
        return 'pending-phone:'.$this->value;
    }
}

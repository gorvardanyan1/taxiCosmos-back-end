<?php

namespace App\Exceptions;

/**
 * An action on a driver or a driver's vehicle that its current state (or who they are) does not allow (HTTP 409).
 */
class DriverStateException extends ConflictException
{
    public static function onTrip(): self
    {
        return new self(__('This driver is on a trip. Force-cancel or reassign the trip first.'));
    }

    public static function notActive(): self
    {
        return new self(__('Only active drivers can be suspended.'));
    }

    public static function notSuspended(): self
    {
        return new self(__('Only suspended drivers can be reactivated.'));
    }

    public static function adminAccount(): self
    {
        return new self(__('This account has admin access. Admin accounts are managed under Settings → Users.'));
    }

    public static function ownAccount(): self
    {
        return new self(__('You cannot suspend your own account.'));
    }

    public static function deletedAccount(): self
    {
        return new self(__('This driver\'s account was deleted. It can no longer be changed.'));
    }

    public static function vehicleNotActive(): self
    {
        return new self(__('Only an active vehicle can be the primary vehicle.'));
    }

    public static function alreadyPrimary(): self
    {
        return new self(__('This vehicle is already the primary vehicle.'));
    }
}

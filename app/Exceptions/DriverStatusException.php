<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A driver cannot go online, go offline or report a position in their current state. The apps branch on the stable `code`:
 * account standing (suspended, not verified, expired documents) is 403, a state the driver can fix or wait out (no vehicle,
 * offline, on a trip) is 409, and a position that could not be stored is 503 (send it again).
 */
class DriverStatusException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function suspended(): self
    {
        return new self(__('Your account is suspended.'), 'driver_suspended', 403);
    }

    public static function notVerified(): self
    {
        return new self(__('Your driver profile has not been approved yet.'), 'driver_not_verified', 403);
    }

    public static function documentsExpired(): self
    {
        return new self(__('One of your documents has expired. Upload a new one to continue.'), 'documents_expired', 403);
    }

    public static function noActiveVehicle(): self
    {
        return new self(__('Add an approved primary vehicle to go online.'), 'no_active_vehicle', 409);
    }

    public static function offline(): self
    {
        return new self(__('Go online before sending your location.'), 'driver_offline', 409);
    }

    public static function onTrip(): self
    {
        return new self(__('You cannot go offline during a trip.'), 'driver_on_trip', 409);
    }

    public static function locationUnavailable(): self
    {
        return new self(__('Your location could not be saved. Please try again.'), 'location_unavailable', 503);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}

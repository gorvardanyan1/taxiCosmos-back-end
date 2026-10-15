<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A driver-app onboarding call the driver's current state does not allow (P4-T4). The app branches on the stable `code`; every one is 409.
 */
class DriverOnboardingException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function applicationRequired(): self
    {
        return new self(__('Apply as a driver first.'), 'driver_application_required');
    }

    public static function vehicleLimit(): self
    {
        return new self(__('You have reached the number of vehicles you can keep. Remove one first.'), 'vehicle_limit_reached');
    }

    public static function vehicleOnTrip(): self
    {
        return new self(__('This vehicle is on a trip. Remove it after the trip.'), 'vehicle_on_trip');
    }

    public static function bankAccountLimit(): self
    {
        return new self(__('You have reached the number of payout accounts you can keep. Remove one first.'), 'bank_account_limit_reached');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], 409);
    }
}

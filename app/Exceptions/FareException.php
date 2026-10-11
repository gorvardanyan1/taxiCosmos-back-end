<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A fare estimate or quote the rider cannot use. Renders itself as the JSON the app switches on:
 * `code` is stable (outside_service_area, no_fare_rules, quote_not_found, quote_expired), `message` is
 * in the rider's language.
 */
class FareException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function outsideServiceArea(): self
    {
        return new self(__('Sorry, we do not serve this pickup location yet.'), 'outside_service_area', 422);
    }

    public static function noFareRules(): self
    {
        return new self(__('Prices are not available for this area yet.'), 'no_fare_rules', 422);
    }

    public static function quoteNotFound(): self
    {
        return new self(__('This price quote does not exist.'), 'quote_not_found', 404);
    }

    public static function quoteExpired(): self
    {
        return new self(__('This price quote has expired. Please get a new estimate.'), 'quote_expired', 410);
    }

    public static function vehicleClassNotQuoted(): self
    {
        return new self(__('This vehicle class is not part of the price quote.'), 'vehicle_class_not_quoted', 422);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}

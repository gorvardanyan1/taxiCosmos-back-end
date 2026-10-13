<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A maps request the app cannot use. Renders itself as the JSON the apps switch on: `code` is stable
 * (geo_provider_unavailable, place_not_found, route_not_found), `message` is in the user's language and never carries
 * a provider URL, key or response body.
 */
class GeoException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    /** The provider timed out, refused the request or answered with an error. */
    public static function providerUnavailable(): self
    {
        return new self(__('The maps service is not available right now. Please try again.'), 'geo_provider_unavailable', 502);
    }

    public static function placeNotFound(): self
    {
        return new self(__('We could not find that place.'), 'place_not_found', 404);
    }

    public static function routeNotFound(): self
    {
        return new self(__('We could not find a route between these points.'), 'route_not_found', 404);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}

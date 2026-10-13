<?php

namespace App\Services\Geo;

use App\Contracts\GeoProvider;
use App\Data\GeoEstimate;
use App\Data\GeoPoint;
use App\Exceptions\GeoException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What the HTTP maps providers share: one timeout, one way to fail. A provider call that times out, is refused or
 * answers with an error becomes GeoException::providerUnavailable(), and only the provider, the operation and the HTTP
 * status are logged: the URL (2GIS puts its key in the query string) and the response body never are.
 */
abstract class HttpGeoProvider implements GeoProvider
{
    abstract protected function name(): string;

    public function estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate
    {
        return $this->getRoute($origin, $destination)?->estimate();
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('services.maps.timeout'))->acceptJson();
    }

    /**
     * Sends the request; a transport failure or an HTTP error status is a provider failure.
     *
     * With `$allowNotFound` an HTTP 404 is "no such thing" (null), not a failure.
     *
     * @param  Closure(PendingRequest): Response  $request
     * @return ($allowNotFound is true ? Response|null : Response)
     */
    protected function send(string $operation, Closure $request, bool $allowNotFound = false): ?Response
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException) {
            $this->fail($operation, null);
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            $this->fail($operation, $response->status());
        }

        return $response;
    }

    protected function fail(string $operation, ?int $status): never
    {
        Log::warning('Maps provider call failed', ['provider' => $this->name(), 'operation' => $operation, 'status' => $status]);

        throw GeoException::providerUnavailable();
    }
}

<?php

namespace App\Contracts;

use App\Data\GeoEstimate;
use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use App\Data\GeoSuggestion;
use App\Exceptions\GeoException;

/**
 * The maps provider (address search, geocoding, routes), behind one interface so Google Maps and 2GIS are
 * interchangeable: MAPS_PROVIDER picks the implementation in AppServiceProvider and no caller knows which one it got.
 * Every method returns the provider-neutral DTOs of App\Data, never a provider's own response.
 *
 * "Nothing found" is a normal answer (an empty list or null); a provider that failed (timeout, quota, a bad key)
 * throws GeoException::providerUnavailable() with nothing sensitive in the message.
 */
interface GeoProvider
{
    /**
     * Address and place suggestions for what the user has typed so far, nearest to `$near` first when given.
     * `$sessionToken` ties the keystrokes and the final placeDetails() call into one billed session (Google).
     *
     * @return list<GeoSuggestion>
     *
     * @throws GeoException
     */
    public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array;

    /**
     * The place behind a suggestion: its name, address and coordinates. Null when the provider does not know the id.
     *
     * @throws GeoException
     */
    public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace;

    /**
     * The best match for an address typed in full, or null.
     *
     * @throws GeoException
     */
    public function geocode(string $address): ?GeoPlace;

    /**
     * The address at a point (the pickup pin), or null where there is none.
     *
     * @throws GeoException
     */
    public function reverseGeocode(GeoPoint $point): ?GeoPlace;

    /**
     * The driving route: distance, duration and the path as an encoded polyline. Null when there is no route.
     *
     * @throws GeoException
     */
    public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute;

    /**
     * Distance and driving time only (no path), for callers that do not draw the route. Null when there is no route.
     *
     * @throws GeoException
     */
    public function estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate;
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\GeoProvider;
use App\Exceptions\GeoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GeoAutocompleteRequest;
use App\Http\Requests\Api\V1\GeoPlaceRequest;
use App\Http\Requests\Api\V1\GeoReverseRequest;
use App\Http\Requests\Api\V1\GeoRouteRequest;
use Illuminate\Http\JsonResponse;

/**
 * The maps the rider and driver apps use (address search, pickup pin, route preview). Every answer is the provider-neutral
 * shape of App\Data\Geo*, whichever provider MAPS_PROVIDER names. Errors carry a stable `code`: place_not_found,
 * route_not_found, geo_provider_unavailable (see GeoException). Documented in docs/geo.md.
 */
class GeoController extends Controller
{
    public function __construct(private readonly GeoProvider $geo) {}

    public function autocomplete(GeoAutocompleteRequest $request): JsonResponse
    {
        $suggestions = $this->geo->autocomplete($request->text(), $request->near(), $request->searchSession());

        return response()->json(['suggestions' => array_map(fn ($suggestion) => $suggestion->toArray(), $suggestions)]);
    }

    public function place(GeoPlaceRequest $request, string $placeId): JsonResponse
    {
        $place = $this->geo->placeDetails($placeId, $request->searchSession()) ?? throw GeoException::placeNotFound();

        return response()->json(['place' => $place->toArray()]);
    }

    public function reverse(GeoReverseRequest $request): JsonResponse
    {
        $place = $this->geo->reverseGeocode($request->point()) ?? throw GeoException::placeNotFound();

        return response()->json(['place' => $place->toArray()]);
    }

    public function route(GeoRouteRequest $request): JsonResponse
    {
        $route = $this->geo->getRoute($request->origin(), $request->destination()) ?? throw GeoException::routeNotFound();

        return response()->json(['route' => $route->toArray()]);
    }
}

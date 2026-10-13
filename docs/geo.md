# Maps: the GeoProvider

Everything the apps need from a map (address search, naming the pickup pin, a route preview, an ETA) goes through one
interface, `App\Contracts\GeoProvider`. **`MAPS_PROVIDER`** picks the implementation; nothing else in the code knows which
one it is. Controllers and services ask the container for `GeoProvider` and never call Google or 2GIS themselves.

| `MAPS_PROVIDER` | Class | APIs used | Key |
| --- | --- | --- | --- |
| `google` (default outside tests) | `GoogleMapsProvider` | Places API (New) autocomplete and details, Geocoding API, Routes API `computeRoutes` | `GOOGLE_MAPS_API_KEY`, sent in the `X-Goog-Api-Key` header |
| `2gis` | `TwoGisProvider` | Suggest API, Places `items/byid`, Geocoder `items/geocode`, Routing API 7.0.0 | `TWOGIS_API_KEY`, sent in the query string |
| `fake` | `FakeGeoProvider` | none: deterministic answers computed locally | none; refused outside `local`, `testing` and `e2e` |

An unknown name stops the app with a clear error instead of falling back. The HTTP client is Laravel's `Http` (no SDKs).

## The interface

```php
autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array   // list<GeoSuggestion>
placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
geocode(string $address): ?GeoPlace
reverseGeocode(GeoPoint $point): ?GeoPlace
getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute        // distance, duration, encoded polyline
estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate  // distance and duration only (cheaper)
```

- "Nothing found" is `null` (or an empty list for autocomplete). A provider outage, a bad key or a timeout throws
  `GeoException::providerUnavailable()` (502, code `geo_provider_unavailable`). The exception's message is fixed: no key, URL
  or provider body ever reaches a response; the log line carries only the provider, the operation and the HTTP status.
- DTOs live in `app/Data` (`GeoSuggestion`, `GeoPlace`, `GeoRoute`, `GeoEstimate`, `GeoPoint`) and are identical for every
  provider. Route paths are **Google encoded polylines** (precision 5): 2GIS's WKT is converted (`App\Support\EncodedPolyline`).
- Search sessions: Google bills autocomplete + the details call that ends it as one session when they share a token, so the
  apps send the same `session` to both. 2GIS has no sessions and ignores it.
- The language of results follows the app locale (en, ru, hy).

## Cost control

`CachingGeoProvider` wraps whichever provider is bound. It caches **reverse geocoding, routes and ETAs** only (autocomplete and
details are never cached). Coordinates are rounded to `MAPS_CACHE_PRECISION` decimals (4, about 11 m) so a pin moved a metre
reuses the answer. The key carries the provider and the language. Only answers are cached: "not found" and failures are asked again.
`MAPS_CACHE_REVERSE_TTL` (3600 s) and `MAPS_CACHE_ROUTE_TTL` (600 s) set the lifetimes; 0 turns a cache off.
Every endpoint is rate limited (`geo`, see `rate-limits.md`).

## Endpoints (rider and driver apps)

All need a mobile Sanctum token with the `rider` or `driver` ability (a session still waiting for a phone number gets 403).
Answers:

| Request | 200 body | Errors |
| --- | --- | --- |
| `GET /api/v1/geo/autocomplete?q=&lat=&lng=&session=` (`q` 2-120 chars; `lat`+`lng` together or not at all; `session` letters, digits, `_`, `-`, up to 64) | `{"suggestions":[{"place_id","text","primary_text","secondary_text"}]}` (empty list when nothing matches) | 422 |
| `GET /api/v1/geo/places/{placeId}?session=` | `{"place":{"place_id","name","address","location":{"lat","lng"}}}` | 404 `place_not_found`, 422 |
| `GET /api/v1/geo/reverse?lat=&lng=` | `{"place":{...}}` (the `location` is the pin that was asked about) | 404 `place_not_found`, 422 |
| `GET /api/v1/geo/route?from=lat,lng&to=lat,lng` | `{"route":{"distance_meters","duration_seconds","polyline"}}` | 404 `route_not_found`, 422 |

Every error is `{"message", "code"}` (422 is Laravel's `{"message","errors"}`); 502 `geo_provider_unavailable` when the
provider fails; 429 with `Retry-After` over the limit.

## Not done here

- `App\Services\Fares\RouteEstimator` (straight line x detour factor) still prices fare estimates. Switching it to
  `GeoProvider::estimateEta()` changes prices and needs its own task (and a fallback for outages).
- Live Google and 2GIS calls were not made while building this (no keys in the workspace). The request and response
  shapes follow the providers' documentation and are tested against faked HTTP. Run `GOOGLE_MAPS_API_KEY=... MAPS_PROVIDER=google`
  against the four endpoints once before launch.

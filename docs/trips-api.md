# Mobile trip API (P7-T6)

The booking and ride endpoints of the rider and driver apps. Base path `/api/v1`, Sanctum bearer token with the `rider` or `driver` ability. Money is
integer minor units with a currency; times are ISO 8601. Cash is the only payment method for now (card and wallet come with their gateways).

## Rules that apply to every call

* **Idempotency.** Every `POST` on a trip needs an `Idempotency-Key` header (8 to 64 of `A-Za-z0-9_-`; a UUID is fine). The first call with a key runs;
  a repeat with the same key and the same request returns the **first answer** (`Idempotent-Replayed: true`) and changes nothing, so an app can retry after a
  dropped connection. Only answers that succeeded are kept (for a day); a refusal is not, so the retry meets the real state.

  | Answer | When |
  | --- | --- |
  | `422 idempotency_key_required` | the header is missing or not 8 to 64 of `A-Za-z0-9_-` |
  | `422 idempotency_key_reused` | the key was used for a different method, path or body |
  | `409 idempotency_in_progress` (`Retry-After: 1`) | the first call with this key is still running |

* **Ownership.** A rider only reaches their own trips and a driver only trips assigned to them. Anything else is `404`, so no one can learn that another trip exists.
* **409 resync.** A step the trip's state does not allow (`start` before `arrived`, cancelling a ride in progress, a second `complete`) is `409` with
  `code: "trip_state_conflict"` and `trip`, the trip as it is now in the shape of that app. Show it instead of guessing.
* **One active trip per rider** (`requested`, `matched`, `arrived`, `in_progress`), enforced by the database.
* Every status change publishes `trip.status_changed` (docs/realtime-events.md) and, for the rider, sends the push of docs/push-notifications.md. The apps
  still read the trip over REST: the event only says when to look.
* Limits: `RATE_LIMIT_TRIPS` per minute (docs/rate-limits.md), then `429` with `Retry-After`.

## Status flow

```
requested ──driver accepts──▶ matched ──arrived──▶ arrived ──start──▶ in_progress ──complete──▶ completed
    │  ▲                         │                    │
    │  └──driver cancels─────────┴────────────────────┘      (back to dispatch, driver_id cleared)
    ├──rider cancels──▶ cancelled (also from matched / arrived)
    └──no driver in time──▶ no_driver_found
```

A ride in progress cannot be cancelled by the rider or the driver (an admin can). A driver who is given back a trip is free again for the next offer.

## Rider

### `POST /trips`: book a trip from an estimate

Body: `quote_id` (from `POST /trips/fare-estimate`), `vehicle_class`, `payment_method` (`cash`), `pickup` and `dropoff` as `{lat, lng, address}`. The price is the
one the rider saw; the pickup and drop-off must be where the estimate was made (within `TRIP_QUOTE_TOLERANCE_METERS`, 100 m).

`201` with the trip (below). Errors: `404 quote_not_found` (unknown, or someone else's), `410 quote_expired`, `422 vehicle_class_not_quoted`, `422 quote_route_changed`,
`422 outside_service_area`, `409 active_trip_exists` (with `trip`: the one in progress), `422` with `errors.payment_method` for card and wallet.

### `GET /trips/current`

The trip in progress, or `204` with no body.

### `GET /trips/{id}`

```json
{
  "data": {
    "id": 18,
    "code": "TK-7H4M9QXD",
    "status": "matched",
    "vehicle_class": "economy",
    "pickup": { "lat": 40.18, "lng": 44.51, "address": "Republic Square, Yerevan" },
    "dropoff": { "lat": 40.19, "lng": 44.51, "address": "Cascade, Yerevan" },
    "fare": { "currency": "AMD", "estimated": 800, "final": null },
    "payment": { "method": "cash", "status": "unpaid" },
    "driver": { "name": "Karen Petrosyan", "rating_avg": 4.85, "rating_count": 12 },
    "vehicle": { "make": "Toyota", "model": "Camry", "color": "silver", "plate_number": "12 AB 345", "vehicle_class": "economy" },
    "eta_to_pickup_seconds": 174,
    "distance_meters": null,
    "duration_seconds": null,
    "cancelled_by": null,
    "cancel_reason": null,
    "requested_at": "2026-10-18T10:00:00+00:00",
    "matched_at": "2026-10-18T10:00:20+00:00",
    "arrived_at": null,
    "started_at": null,
    "completed_at": null,
    "cancelled_at": null
  }
}
```

`driver` and `vehicle` are `null` until a driver has the trip; the driver's phone number is never sent. `eta_to_pickup_seconds` is only set while the driver is on the way
(`matched`) and their position is known. `fare.final`, `distance_meters` and `duration_seconds` are set when the ride is completed.

### `GET /trips`: history

Finished trips (`completed`, `cancelled`, `no_driver_found`), newest first, 20 a page: `{ "data": [trip…], "meta": { "current_page", "last_page", "per_page", "total" } }`.

### `POST /trips/{id}/cancel`

Optional body `reason` (up to 500 characters). Allowed while the trip is `requested`, `matched` or `arrived`; answers the cancelled trip. No cancellation fee is charged yet (cash only).

### `GET /trips/{id}/receipt`

```json
{
  "data": {
    "trip_id": 18,
    "code": "TK-7H4M9QXD",
    "completed_at": "2026-10-18T10:21:00+00:00",
    "pickup_address": "Republic Square, Yerevan",
    "dropoff_address": "Cascade, Yerevan",
    "distance_meters": 1446,
    "duration_seconds": 600,
    "currency": "AMD",
    "breakdown": {
      "base": 500,
      "distance": 217,
      "time": 200,
      "waiting": 0,
      "minimum_adjustment": 0,
      "booking_fee": 0,
      "fees": [],
      "surge_multiplier": "1.00",
      "surge": 0,
      "tax": 0
    },
    "total": 917,
    "payment": { "method": "cash", "status": "paid" }
  }
}
```

The lines add up to `total`. `409 trip_not_completed` before the trip is completed.

## Driver

A driver only sees what is needed to do the trip: the rider's **first name** and the addresses, never a phone number.

### `GET /driver/trips/current`

The trip they are on (`matched`, `arrived` or `in_progress`), or `204`.

```json
{
  "data": {
    "id": 18,
    "code": "TK-7H4M9QXD",
    "status": "in_progress",
    "vehicle_class": "economy",
    "pickup": { "lat": 40.18, "lng": 44.51, "address": "Republic Square, Yerevan" },
    "dropoff": { "lat": 40.19, "lng": 44.51, "address": "Cascade, Yerevan" },
    "rider": { "first_name": "Anna" },
    "fare": { "currency": "AMD", "estimated": 800, "final": null },
    "payment": { "method": "cash", "status": "unpaid" },
    "earnings": null,
    "distance_meters": null,
    "duration_seconds": null,
    "cancelled_by": null,
    "requested_at": "2026-10-18T10:00:00+00:00",
    "matched_at": "2026-10-18T10:00:20+00:00",
    "arrived_at": "2026-10-18T10:06:00+00:00",
    "started_at": "2026-10-18T10:07:00+00:00",
    "completed_at": null,
    "cancelled_at": null
  }
}
```

After `complete`, `fare.final` is what the driver collects in cash, `payment.status` is `paid` and `earnings` is `{ "currency": "AMD", "amount": 734, "commission": 183 }`
(the driver's share and the platform's commission; they add up to the fare).

### `GET /driver/trips`: history

Completed and cancelled trips, newest first, 20 a page, with `earnings` on completed ones. Same `data` and `meta` shape as the rider's history.

### `POST /driver/trips/{id}/arrived`, `/start`

`matched → arrived → in_progress`. No body. Answer: the trip.

### `POST /driver/trips/{id}/complete`

`in_progress → completed`. No body: the server measures the ride and prices it. The distance is the sum of the driver's position pings during the ride
(docs/driver-location.md; legs further apart in time than `TRIP_PING_GAP_SECONDS` or faster than `TRIP_MAX_SPEED_KMH` are left out), falling back to the quoted route when
there are fewer than two pings. The duration is the time since the start, the waiting time is the time between arriving and starting (the rule's free minutes come off).
The same fare rule and surge the rider was quoted are applied to those numbers (docs/fares.md), the commission rule of the zone splits the fare (docs/commission.md), cash is taken as
paid, and the driver is free again. The pricing is frozen once saved.

### `POST /driver/trips/{id}/cancel`

Body `reason` (required, 3 to 500 characters). Allowed while `matched` or `arrived`: the trip goes back to `requested` (no driver) and to dispatch again, the reason is kept
(`trip_driver_cancellations`), and the driver is free again. `409` for a ride in progress or a finished trip.

### `GET /driver/earnings`

```json
{
  "data": {
    "currency": "AMD",
    "today": { "trips": 3, "fare": 4100, "commission": 820, "earnings": 3280 },
    "week": { "trips": 11, "fare": 14500, "commission": 2900, "earnings": 11600 },
    "month": { "trips": 40, "fare": 52000, "commission": 10400, "earnings": 41600 }
  }
}
```

Added up from completed trips. A day is a calendar day in the timezone of the driver's home zone (UTC without one): `today` is since their midnight, `week` the last 7
days and `month` the last 30 days, both including today. Trips in another currency are left out. The cash commission the driver owes the platform is not tracked here yet.

## For dispatch (P7-T7)

`TripRequested` (`App\Events\TripRequested`) fires after the commit when a trip needs a driver: just requested, or given back by a driver. When a driver accepts, call
`TripService::assignDriver($trip, $driverProfile)`: it matches the trip, sets the driver's primary vehicle, puts the driver on a trip and announces the change. It refuses
(409) a trip that is no longer waiting and a driver who is not online, and when two drivers accept at once exactly one wins. `trip_driver_cancellations` says which drivers
already gave this trip back.

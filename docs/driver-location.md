# Driver location and availability (P8-T2)

The driver app tells the backend where it is and whether it takes trips. Live positions normally go **straight from the Node/Socket.io service to Redis** (`GEOADD`); the two REST endpoints below are the fallback the app uses when the socket is not available, and the only place the *online* switch is validated. Everything is for the **driver app** (`ability:driver`): a rider token gets 403, a guest 401.

## Endpoints

| Request | Success | Refusals |
| --- | --- | --- |
| `POST /api/v1/driver/location` `{lat, lng, heading?, speed?, accuracy?}` | `200 {"recorded_at": "2026-10-14T10:00:00.250Z"}` | 422 (bad numbers), 403 `driver_not_verified` / `documents_expired`, 409 `driver_offline`, 503 `location_unavailable`, 429 |
| `POST /api/v1/driver/availability` `{availability: "online" \| "offline"}` | `200 {"availability": "online" \| "offline" \| "on_trip"}` | 422, 403 `driver_not_verified` / `documents_expired`, 409 `no_active_vehicle` / `driver_on_trip`, 429 |

Every refusal is `{"message": "...", "code": "..."}` (422 is Laravel's `{"message", "errors"}`); the message is in the app language. A suspended account's tokens are revoked, so it gets 401 on its next call, and a token issued to a suspended account is refused with a plain 403 by the app gate before these endpoints run. (`DriverAvailabilityService` also refuses a suspended account with `driver_suspended` for any caller that is not the HTTP gate.)

### Location rules

* The driver is **always the signed-in one**; nothing in the body names a driver.
* Accepted only while the driver is **verified** and **online or on a trip** (a driver on a trip keeps reporting). Offline: 409. Verified means `verification_status = approved` **and** no required document past its expiry date (checked live, because the stored status lags until the expiry job runs).
* `lat` is `-85.05112878…85.05112878` (the limit of Redis GEO, not 90), `lng` `-180…180`, `heading` `0…360` degrees (360 is stored as 0), `speed` `0…100` m/s, `accuracy` `0…10000` m. Anything else is a 422 and nothing is stored.
* The time is the **server's** (`recorded_at`); the app's clock is not trusted.
* Limited to `RATE_LIMIT_DRIVER_LOCATION` (30) a minute per driver.

### Availability rules

* **Online** is refused when the account is suspended, the driver is not approved, a required document has expired, or there is **no active primary vehicle** (the primary vehicle must have status `active`). Going online again while already online (or on a trip) changes nothing.
* **Offline** is refused during a trip (409 `driver_on_trip`) and is a no-op when already offline.
* The change is saved through the model, so `driver.availability_changed` is published (after commit) only when something changed.
* Going online or offline **removes any old position** of that driver from Redis, so a position from an earlier session never passes for the current one.
* A suspension (admin) also removes the position at once.
* Limited to `RATE_LIMIT_DRIVER_AVAILABILITY` (10) a minute per driver.

## Redis (shared with the Node service)

Redis **6.2 or newer** is required (`GEOSEARCH`, `ZMSCORE`), as a single node or with Sentinel: the write is one script over both keys, which a Redis Cluster would refuse (the two key names are fixed, so they do not share a hash slot).

Both keys are used **exactly as named** (raw commands, no Laravel `REDIS_PREFIX`), on the real-time connection (`REALTIME_REDIS_CONNECTION`). Members are the driver's **user id**.

| Key | Type | Content |
| --- | --- | --- |
| `drivers:locations` (`REALTIME_GEO_KEY`) | geo set (sorted set) | position per driver; dispatch (P7-T7) uses `GEOSEARCH` on it |
| `drivers:last_seen` (`REALTIME_LAST_SEEN_KEY`) | sorted set | unix time (seconds) of the driver's last ping, so stale positions can be found and pruned |

A ping writes both with one script (`GEOADD` + `ZADD`), so they never disagree. The Node service should do the same (or `ZADD` the time right after its `GEOADD`). Tests and the e2e environment use `test:drivers:*` / `e2e:drivers:*` so they never touch real positions. Redis may hold a position of a driver who just went offline (a ping racing the switch); the sweep below removes it, and dispatch must still check the driver's state in PostgreSQL.

## MongoDB (history)

Each accepted ping is also appended to the `driver_location_pings` collection (model `App\Models\DriverLocationPing`) **after the response is sent** (a failure is logged with ids only and does not fail the ping):

```json
{ "driver_user_id": 42, "driver_profile_id": 7, "location": {"type": "Point", "coordinates": [44.4991, 40.1792]},
  "heading": 270, "speed": 12.5, "accuracy": 8.0, "source": "rest", "recorded_at": "2026-10-14T10:00:00.250Z" }
```

Indexes (migration `create_driver_location_pings_collection`): `driver_history` on `(driver_user_id, recorded_at desc)` and a **TTL index on `recorded_at`**: `DRIVER_PING_RETENTION_DAYS` (default 90) days. The retention is applied when the index is created; to change it, drop the `recorded_at_1` index and run the migration again. Mongo is history only; PostgreSQL and the Redis geo set decide everything live.

## Stale drivers (scheduled every minute)

`php artisan drivers:mark-stale-offline` (`withoutOverlapping`, `onOneServer`, in `routes/console.php`):

1. an **online** driver whose last ping is `DRIVER_STALE_AFTER_MINUTES` (default 5) old or older, or who **never pinged** since going online that long ago, is set offline through the model (so `driver.availability_changed` is published) and removed from Redis. The row is locked and the ping time read again first, so a ping that arrives meanwhile saves the driver;
2. a stale position of a driver who is offline (or no longer exists) is pruned from Redis;
3. a driver **on a trip is never touched** (a lost GPS signal must not end the trip's tracking).

## Events

* `driver.availability_changed`: on every real change (switch, sweep, suspension).
* `driver.location_updated`: for each accepted ping, after the Redis write (see `realtime-events.md`).

# Real-time events (Redis Streams)

Laravel publishes **state-change events** to Redis Streams. A separate **Node/Socket.io service** reads them through a **consumer group** and pushes them to the apps and the admin. Laravel never talks to a socket: **Redis is the only integration point** (plus the token-check endpoint in P8-T3). Source of truth in code: `App\Realtime\RealtimeEventType` (stream, version, payload keys); this document is tested against it (`RealtimeEventsDocumentTest`).

## Transport

| | |
| --- | --- |
| Mechanism | `XADD` (Redis Streams), **not** `PUBLISH`: entries persist until trimmed, so a consumer that is restarting or briefly down catches up on reconnect |
| Streams | **One stream per event type**, key = `REALTIME_STREAM_PREFIX` + type, default prefix `taxikosmos:events:` (e.g. `taxikosmos:events:trip.status_changed`) |
| Redis connection | `REALTIME_REDIS_CONNECTION` (default `default`), database as configured for that connection |
| Key prefix | Streams are written with raw commands, so Laravel's global `REDIS_PREFIX` is **not** added: use the keys exactly as listed below |
| Trimming | `XADD key MAXLEN ~ 100000 …` per stream (approximate; `REALTIME_STREAM_MAXLEN`). A consumer down long enough for 100k newer events to arrive misses the oldest ones and must resync over REST |
| Entry fields | Flat string fields: `event_id`, `type`, `version`, `occurred_at`, `payload` (**JSON string**) |

**Why one stream per type:** each type can be consumed, trimmed and monitored on its own, and a noisy type (location) cannot push important ones (offers, suspensions) out of the window. The cost is that ordering is guaranteed **within a stream only**. If you need cross-type order for one trip, sort by `occurred_at` (millisecond UTC) after reading.

## Envelope

Every event carries the same envelope (shown here as JSON; in the stream `payload` is a JSON-encoded string field):

| Field | Type | Meaning |
| --- | --- | --- |
| `event_id` | UUID string | Unique per occurrence. **Deduplicate on it**: redelivery after a crash returns the same `event_id` |
| `type` | string | One of the types below (equals the stream key suffix) |
| `version` | integer | Payload schema version of that type. Currently `1` for all. Ignore (or park) versions you do not know; a breaking payload change bumps it |
| `occurred_at` | UTC timestamp, millisecond precision, `YYYY-MM-DDTHH:MM:SS.mmmZ` | When the change happened (not when it was committed or read) |
| `payload` | object | Type-specific, see below. Money is always `{ "amount": <integer minor units>, "currency": "AMD" }` |

New payload keys may be **added** within a version; consumers must ignore keys they do not know.

## Delivery guarantees

* **After commit only.** Events are dispatched with `ShouldDispatchAfterCommit`: the `XADD` happens only after the surrounding database transaction commits; if it rolls back (including a rolled-back savepoint) nothing is published. Order inside one transaction is preserved.
* **At-least-once to the consumer group.** Entries stay pending until `XACK`. A consumer that crashes before acknowledging gets the same entry again, so handlers must be idempotent (dedupe on `event_id`).
* **Best-effort publish.** If Redis is unreachable at the moment of the `XADD`, the failure is logged (event id and type only, never the payload) and the already-committed change is **not** rolled back; that event is lost. Clients must therefore refetch state over REST when they (re)connect, and treat events as change hints.
* **Only Eloquent changes are published.** Raw query-builder updates bypass model events; services must change drivers and users through their models.

## Consuming (Node service)

```
# once per stream (idempotent: ignore BUSYGROUP)
XGROUP CREATE taxikosmos:events:user.suspended socket-service 0 MKSTREAM

# loop (BLOCK for live delivery, > = entries never delivered to this group)
XREADGROUP GROUP socket-service node-1 COUNT 100 BLOCK 5000 STREAMS <key1> <key2> ... > > ...

# after delivering to the connected clients
XACK <key> socket-service <entry-id>

# on startup, first re-process your own unacknowledged entries (use id 0 instead of >)
XREADGROUP GROUP socket-service node-1 COUNT 100 STREAMS <key1> <key2> ... 0 0 ...
# and periodically claim entries stuck on a dead consumer
XAUTOCLAIM <key> socket-service node-1 60000 0-0
```

Create the group at `0` to receive everything still in the stream, or `$` to receive only new entries. Run several consumers with the same group name to share the load; each entry goes to exactly one of them. `RealtimeEventsDocumentTest`, `ConsumerGroupTest` and `EventsAfterCommitTest` exercise exactly this against real Redis.

## Event catalogue

| Type | Stream key | Version | Payload keys | Published by | Deliver to |
| --- | --- | --- | --- | --- | --- |
| `trip.offer` | `taxikosmos:events:trip.offer` | 1 | `offer_id`, `trip_id`, `driver_user_id`, `expires_at`, `pickup_address`, `dropoff_address`, `estimated_fare`, `vehicle_class` | P7-T7 | one driver |
| `trip.offer_cancelled` | `taxikosmos:events:trip.offer_cancelled` | 1 | `offer_id`, `trip_id`, `driver_user_id`, `reason` | P7-T7 | one driver |
| `trip.status_changed` | `taxikosmos:events:trip.status_changed` | 1 | `trip_id`, `trip_code`, `from_status`, `to_status`, `rider_user_id`, `driver_user_id` | P7-T6, P7-T7 | rider and driver of the trip |
| `trip.reassigned` | `taxikosmos:events:trip.reassigned` | 1 | `trip_id`, `trip_code`, `rider_user_id`, `previous_driver_user_id`, `new_driver_user_id` | P7-T2 | rider, previous and new driver |
| `trip.fare_adjusted` | `taxikosmos:events:trip.fare_adjusted` | 1 | `trip_id`, `trip_code`, `rider_user_id`, `driver_user_id`, `previous_fare`, `new_fare` | P7-T2 | rider and driver |
| `transaction.status_changed` | `taxikosmos:events:transaction.status_changed` | 1 | `transaction_id`, `trip_id`, `user_id`, `from_status`, `to_status`, `amount` | P6-T1 … P6-T7 | the paying user |
| `driver.verification_changed` | `taxikosmos:events:driver.verification_changed` | 1 | `driver_user_id`, `driver_profile_id`, `from_status`, `to_status` | wired now — `DriverProfileObserver` | the driver |
| `driver.availability_changed` | `taxikosmos:events:driver.availability_changed` | 1 | `driver_user_id`, `driver_profile_id`, `from_availability`, `to_availability` | wired now — `DriverProfileObserver` | the driver (and the admin live map) |
| `user.suspended` | `taxikosmos:events:user.suspended` | 1 | `user_id`, `status` | wired now — `UserObserver` | that user |
| `surge.changed` | `taxikosmos:events:surge.changed` | 1 | `surge_id`, `zone_id`, `vehicle_class`, `multiplier`, `active`, `starts_at`, `ends_at` | P5-T4 | all drivers/riders in the zone |
| `support_ticket.updated` | `taxikosmos:events:support_ticket.updated` | 1 | `ticket_id`, `ticket_code`, `status`, `requester_user_id`, `change` | P7-T5 | the requester |
| `driver.location_updated` | `taxikosmos:events:driver.location_updated` | 1 | `driver_user_id`, `latitude`, `longitude`, `heading`, `recorded_at` | placeholder for P8-T2 | admin live map / the rider of the active trip |

### `trip.offer`

A trip is offered to a single driver. Shows the offer card with a countdown to `expires_at`.

* **Stream:** `taxikosmos:events:trip.offer`  
* **Deliver to:** one driver (route on `driver_user_id`)  
* **Published by:** P7-T7

| Payload field | Type / values |
| --- | --- |
| `offer_id` | integer |
| `trip_id` | integer |
| `driver_user_id` | integer — the only user who should receive it |
| `expires_at` | UTC timestamp |
| `pickup_address` | string |
| `dropoff_address` | string |
| `estimated_fare` | Money `{amount, currency}` (integer minor units) |
| `vehicle_class` | `economy` \| `comfort` \| `business` |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "trip.offer",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "offer_id": 11,
        "trip_id": 501,
        "driver_user_id": 42,
        "expires_at": "2026-10-10T09:16:00.250Z",
        "pickup_address": "Republic Square",
        "dropoff_address": "EVN Airport",
        "estimated_fare": {
            "amount": 12500,
            "currency": "AMD"
        },
        "vehicle_class": "comfort"
    }
}
```

### `trip.offer_cancelled`

The offer is gone (taken by another driver, expired, trip cancelled or reassigned): remove the card.

* **Stream:** `taxikosmos:events:trip.offer_cancelled`  
* **Deliver to:** one driver (route on `driver_user_id`)  
* **Published by:** P7-T7

| Payload field | Type / values |
| --- | --- |
| `offer_id` | integer |
| `trip_id` | integer |
| `driver_user_id` | integer |
| `reason` | `accepted_by_other` \| `expired` \| `trip_cancelled` \| `reassigned` |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "trip.offer_cancelled",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "offer_id": 11,
        "trip_id": 501,
        "driver_user_id": 42,
        "reason": "accepted_by_other"
    }
}
```

### `trip.status_changed`

Every trip status transition. Statuses: `requested`, `matched`, `arrived`, `in_progress`, `completed`, `cancelled`, `no_driver_found`.

* **Stream:** `taxikosmos:events:trip.status_changed`  
* **Deliver to:** rider and driver of the trip (route on `rider_user_id`, `driver_user_id` (null until matched))  
* **Published by:** P7-T6, P7-T7

| Payload field | Type / values |
| --- | --- |
| `trip_id` | integer |
| `trip_code` | string, e.g. `TK-8F3K2` |
| `from_status` | string or null for a new trip |
| `to_status` | string |
| `rider_user_id` | integer |
| `driver_user_id` | integer or null |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "trip.status_changed",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "trip_id": 501,
        "trip_code": "TK-8F3K2",
        "from_status": "matched",
        "to_status": "arrived",
        "rider_user_id": 7,
        "driver_user_id": 42
    }
}
```

### `trip.reassigned`

An admin moved a live trip to another driver.

* **Stream:** `taxikosmos:events:trip.reassigned`  
* **Deliver to:** rider, previous and new driver (route on `rider_user_id`, `previous_driver_user_id`, `new_driver_user_id`)  
* **Published by:** P7-T2

| Payload field | Type / values |
| --- | --- |
| `trip_id` | integer |
| `trip_code` | string |
| `rider_user_id` | integer |
| `previous_driver_user_id` | integer or null |
| `new_driver_user_id` | integer |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "trip.reassigned",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "trip_id": 501,
        "trip_code": "TK-8F3K2",
        "rider_user_id": 7,
        "previous_driver_user_id": 42,
        "new_driver_user_id": 43
    }
}
```

### `trip.fare_adjusted`

An admin changed the fare. The internal reason is not published.

* **Stream:** `taxikosmos:events:trip.fare_adjusted`  
* **Deliver to:** rider and driver (route on `rider_user_id`, `driver_user_id`)  
* **Published by:** P7-T2

| Payload field | Type / values |
| --- | --- |
| `trip_id` | integer |
| `trip_code` | string |
| `rider_user_id` | integer |
| `driver_user_id` | integer or null |
| `previous_fare` | Money |
| `new_fare` | Money |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "trip.fare_adjusted",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "trip_id": 501,
        "trip_code": "TK-8F3K2",
        "rider_user_id": 7,
        "driver_user_id": 42,
        "previous_fare": {
            "amount": 12500,
            "currency": "AMD"
        },
        "new_fare": {
            "amount": 11000,
            "currency": "AMD"
        }
    }
}
```

### `transaction.status_changed`

A payment or refund changed status (for example `pending` → `completed`).

* **Stream:** `taxikosmos:events:transaction.status_changed`  
* **Deliver to:** the paying user (route on `user_id`)  
* **Published by:** P6-T1 … P6-T7

| Payload field | Type / values |
| --- | --- |
| `transaction_id` | integer |
| `trip_id` | integer or null |
| `user_id` | integer |
| `from_status` | string or null |
| `to_status` | string |
| `amount` | Money |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "transaction.status_changed",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "transaction_id": 9001,
        "trip_id": 501,
        "user_id": 7,
        "from_status": "pending",
        "to_status": "completed",
        "amount": {
            "amount": 12500,
            "currency": "AMD"
        }
    }
}
```

### `driver.verification_changed`

`driver_profiles.verification_status` changed (`pending`, `approved`, `rejected`, `expired`).

* **Stream:** `taxikosmos:events:driver.verification_changed`  
* **Deliver to:** the driver (route on `driver_user_id`)  
* **Published by:** wired now — `DriverProfileObserver`

| Payload field | Type / values |
| --- | --- |
| `driver_user_id` | integer |
| `driver_profile_id` | integer |
| `from_status` | string |
| `to_status` | string |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "driver.verification_changed",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "driver_user_id": 42,
        "driver_profile_id": 5,
        "from_status": "pending",
        "to_status": "approved"
    }
}
```

### `driver.availability_changed`

`driver_profiles.availability` changed (`offline`, `online`, `on_trip`).

* **Stream:** `taxikosmos:events:driver.availability_changed`  
* **Deliver to:** the driver (and the admin live map) (route on `driver_user_id`)  
* **Published by:** wired now — `DriverProfileObserver`

| Payload field | Type / values |
| --- | --- |
| `driver_user_id` | integer |
| `driver_profile_id` | integer |
| `from_availability` | string |
| `to_availability` | string |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "driver.availability_changed",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "driver_user_id": 42,
        "driver_profile_id": 5,
        "from_availability": "offline",
        "to_availability": "online"
    }
}
```

### `user.suspended`

**The socket service must disconnect every socket of this user.** Raised when `users.status` becomes anything other than `active` (`suspended`, `deactivated`, `pending_deletion`). The suspension reason is internal and not published.

* **Stream:** `taxikosmos:events:user.suspended`  
* **Deliver to:** that user (route on `user_id`)  
* **Published by:** wired now — `UserObserver`

| Payload field | Type / values |
| --- | --- |
| `user_id` | integer |
| `status` | `suspended` \| `deactivated` \| `pending_deletion` |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "user.suspended",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "user_id": 42,
        "status": "suspended"
    }
}
```

### `surge.changed`

A surge rule was created, started, ended or changed. `multiplier` is a decimal string.

* **Stream:** `taxikosmos:events:surge.changed`  
* **Deliver to:** all drivers/riders in the zone (route on `zone_id`)  
* **Published by:** P5-T4

| Payload field | Type / values |
| --- | --- |
| `surge_id` | integer |
| `zone_id` | integer |
| `vehicle_class` | string or null (all classes) |
| `multiplier` | decimal string, e.g. `"1.50"` |
| `active` | boolean |
| `starts_at` | UTC timestamp or null |
| `ends_at` | UTC timestamp or null |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "surge.changed",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "surge_id": 3,
        "zone_id": 1,
        "vehicle_class": "comfort",
        "multiplier": "1.50",
        "active": true,
        "starts_at": "2026-10-10T14:00:00.000Z",
        "ends_at": "2026-10-10T16:00:00.000Z"
    }
}
```

### `support_ticket.updated`

A staff reply, status change or assignment. Internal notes never trigger it.

* **Stream:** `taxikosmos:events:support_ticket.updated`  
* **Deliver to:** the requester (route on `requester_user_id`)  
* **Published by:** P7-T5

| Payload field | Type / values |
| --- | --- |
| `ticket_id` | integer |
| `ticket_code` | string |
| `status` | string |
| `requester_user_id` | integer |
| `change` | `reply` \| `status` \| `assignment` |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "support_ticket.updated",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "ticket_id": 77,
        "ticket_code": "TKT-2941",
        "status": "in_progress",
        "requester_user_id": 7,
        "change": "reply"
    }
}
```

### `driver.location_updated`

**Placeholder.** Live positions normally use Redis GEOADD / pub-sub because a missed ping is harmless; the stream exists so the contract is complete.

* **Stream:** `taxikosmos:events:driver.location_updated`  
* **Deliver to:** admin live map / the rider of the active trip (route on `driver_user_id`)  
* **Published by:** placeholder for P8-T2

| Payload field | Type / values |
| --- | --- |
| `driver_user_id` | integer |
| `latitude` | number (WGS84) |
| `longitude` | number (WGS84) |
| `heading` | integer degrees or null |
| `recorded_at` | UTC timestamp |

Example:

```json
{
    "event_id": "3f6c1b0e-5a1d-4c8e-9d2a-7b6e0c4f1a22",
    "type": "driver.location_updated",
    "version": 1,
    "occurred_at": "2026-10-10T09:15:30.123Z",
    "payload": {
        "driver_user_id": 42,
        "latitude": 40.1777,
        "longitude": 44.5126,
        "heading": 270,
        "recorded_at": "2026-10-10T09:16:00.250Z"
    }
}
```

## Adding or changing an event

1. Add the case, stream, `payloadKeys()` and (if breaking) a new `version()` to `RealtimeEventType`.
2. Add an event class in `app/Events/Realtime` extending `RealtimeDomainEvent`, and dispatch it from the service/observer that changes the state (inside the transaction: it is held back until commit).
3. Add it to `Tests\Feature\Realtime\EventFactory` and to this document (the catalogue table and a section with an example); `RealtimeEventsDocumentTest` fails until they match.

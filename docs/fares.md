# Fares and fare estimates (P5-T2)

What a trip costs, how the price is built, how prices are changed, and what the rider app is shown.

## Tables

| Table | What | Notes |
| --- | --- | --- |
| `fare_rules` | The prices of one **vehicle class in one zone for one period**: `base_fare`, `per_km`, `per_minute`, `min_fare`, `waiting_free_minutes`, `waiting_per_minute`, `cancellation_fee`, `cancellation_free_seconds`, `booking_fee`, `tax_rate_bps` (basis points, `0` = none), `tax_inclusive`, `currency`, `effective_from`, `effective_to` (null = still current), `created_by` | Money is integer minor units of the zone's currency. A rule applies when `effective_from <= t < effective_to`. |
| `fare_fees` | Fixed surcharges by place (airport, toll): `name`, `amount`, `currency`, `applies_to` (`pickup`/`dropoff`/`both`), a `zone_id` **or** a `polygon`, effective-dated | No admin screen yet (rows are added by data/seed). A fee is charged once per trip when an end it applies to is inside its zone or area. |
| `fare_quotes` | What a rider was shown: pickup, drop-off, distance, duration, and per class the rule id, surge and full breakdown | Live for `FARE_QUOTE_TTL_MINUTES` (10); expired quotes are deleted a day later (`model:prune`). |

`trips.fare_rule_id` is a foreign key to `fare_rules`, and `trips.fare_breakdown` stores `FareBreakdown::toArray()`.

### History is protected by the database

Prices are never edited: **saving a rate closes the current row (`effective_to = now`) and inserts a new one
(`effective_from = now`)**. The database enforces it, whatever code runs:

- an exclusion constraint (`btree_gist`) refuses two overlapping periods for one zone and class;
- a trigger refuses any `UPDATE` except closing an open row once, and refuses `DELETE`;
- CHECK constraints refuse negative money, tax over 100 %, an unknown class and a period that ends before it starts.

So "what was the base fare for zone X, class Y at time T" is one query:

```sql
SELECT base_fare FROM fare_rules
WHERE zone_id = :zone AND vehicle_class = :class
  AND effective_from <= :t AND (effective_to IS NULL OR effective_to > :t);
```

(`FareRuleLookup::at()` does the same.) Times are stored to the second, so two saves inside one second still produce
two versions: the new one starts a second after the old one began, and the old price stays in force until then.

## FareCalculator

`App\Services\Fares\FareCalculator::calculate(rule, distanceMeters, durationSeconds, waitingSeconds, surgeHundredths, fees)`
returns a `FareBreakdown` (a plain value object stored on the trip). The estimate shown to the rider and the final fare
use the same method; the final fare passes the real distance, time and waiting.

```
metered  = base + distance + time + waiting
surge    = metered x (multiplier - 1)         (not applied to the booking fee, place fees or tax)
minimum  = whatever lifts metered + surge up to min_fare
subtotal = metered + surge + minimum + booking fee + place fees
tax      = subtotal x rate                    (exclusive: added on top)
         = subtotal x rate / (1 + rate)       (inclusive: the part of the subtotal that is tax)
total    = subtotal + tax (exclusive)  or  subtotal (inclusive)
```

- **Rounding:** every product (`per_km x metres / 1000`, `per_minute x seconds / 60`, surge, tax) is rounded half-up to a whole
  minor unit, once per component, with integer arithmetic. Because amounts are minor units, this rounds to the cent for
  a 2-decimal currency and to the unit for AMD, and the printed parts always add up to the total.
- **Surge** is a multiplier in hundredths (`150` = 1.50x), clamped between 1.00 and `FARE_SURGE_CAP_HUNDREDTHS` (default 300).
  `SurgeResolver` returns 1.00 until surge rules exist (P5-T4 fills in that one method; nothing else changes).
- **Waiting** is billed per minute after `waiting_free_minutes`.
- **Cancellation:** `cancellationFee()` charges the rider's `cancellation_fee` only when the **rider** cancels **later than**
  `cancellation_free_seconds` after a driver was matched (exactly at the limit is free); driver, admin and system
  cancellations never charge the rider. `noShowFee()` charges it when the driver marks a no-show after the full
  `waiting_free_minutes` of waiting. `cancellationBreakdown()` builds the breakdown stored on such a trip.

## Changing prices (admin)

`POST /admin/zones/{zone}/fare-rules` (permission `fares.manage`) takes one full version: `vehicle_class` plus every term
(money in minor units). It creates the first rule of a class or adds a version; the currency is always the zone's.
Saving the values already in force changes nothing (no new row, no audit entry), so a double-click is harmless.
The Zones & Fares page shows the prices in force with **Effective from / Effective to**, a pencil that opens a form
("New version of …") and a clock that opens the class's **history** (`?history=<class>`, newest first, with who saved each version).
Audit: `fare_rule.created`, `fare_rule.versioned`.

## Fare estimate (rider app)

`POST /api/v1/trips/fare-estimate` (Sanctum, `rider` ability, `throttle:fare-estimate` 30/min per rider)

```json
{ "pickup": { "lat": 40.18, "lng": 44.51 }, "dropoff": { "lat": 40.19, "lng": 44.51 } }
```

`201`:

```json
{
  "quote_id": "uuid", "expires_at": "2026-10-12T09:10:00+00:00",
  "zone": { "id": 1, "name": "Yerevan" }, "currency": "AMD", "distance_meters": 1446, "duration_seconds": 174,
  "estimates": [
    { "vehicle_class": "economy", "currency": "AMD", "amount": 800, "amount_min": 720, "amount_max": 880, "surge_multiplier": "1.00", "eta_to_pickup_seconds": null }
  ]
}
```

- One entry per vehicle class that has a rule in force in the **pickup zone** (the highest-priority active zone containing it).
  `amount` is the price the trip will be created with; `amount_min`/`amount_max` are ±`FARE_ESTIMATE_RANGE_BPS` around it (10 %).
- Errors have a stable `code`: `422 outside_service_area` (no active zone contains the pickup), `422 no_fare_rules`
  (the zone has no price yet), plus the usual `422` field errors, `401`, `403` (not a rider token), `429`.
  `message` follows the rider's `Accept-Language`.
- **Quotes:** each call stores a `fare_quotes` row. Trip creation (P7-T6) calls `FareQuoteService::usable($quoteId, $rider)`
  (only the rider it was made for; `404 quote_not_found`, `410 quote_expired` after 10 minutes) and `snapshot($quote, $class)`
  to copy the quoted rule id, surge and breakdown onto the trip. A price change after the quote does not affect it.

### Fallbacks until neighbouring tasks land

- **Route (P5-T1 GeoProvider):** distance and duration come from `RouteEstimator`, the straight line lengthened by
  `FARE_ROUTE_DETOUR_PERCENT` (130) and driven at `FARE_ROUTE_AVERAGE_SPEED_KMH` (30). `getRoute` replaces its body.
- **ETA to pickup (P8-T2 driver locations):** `eta_to_pickup_seconds` is `null`.
- **Surge (P5-T4):** always 1.00.

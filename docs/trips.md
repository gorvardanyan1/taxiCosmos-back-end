# Trips

The `trips` table (P2-T4) is the record of a ride. Admin screens (P7-T1) and the mobile API (P7-T6/T7)
build on it; this task adds the schema, model, enums and factory only.

## Locations

`pickup_location` and `dropoff_location` are `geography(Point, 4326)` with GIST indexes
(`trips_pickup_location_gist`, `trips_dropoff_location_gist`) so `ST_DWithin` radius searches use the index.
The model casts them to `App\Data\GeoPoint` (latitude/longitude by name) through `App\Casts\GeoPointCast`,
which reads and writes EWKB hex — no raw SQL needed.

## Status

`App\Enums\TripStatus` is the only definition of the lifecycle:

| From | To |
| --- | --- |
| requested | matched, no_driver_found, cancelled |
| matched | arrived, requested (back to dispatch), cancelled |
| arrived | in_progress, requested (back to dispatch), cancelled |
| in_progress | completed, cancelled (admin only) |
| completed, cancelled, no_driver_found | — |

`TripStatus::canBeCancelledBy()` holds the actor rules: a driver cannot cancel before matching, and only an
admin can force-cancel a ride in progress.

Change status with `Trip::transitionTo()`. It locks the row, re-reads the status (so of two racing accepts
only one wins; the other gets a 409 `TripStateException`), sets the matching timestamp, and clears driver,
vehicle and arrival when the trip goes back to dispatch. A plain `save()` with an illegal status change throws
too. CHECK constraints stop a row from contradicting its status (matched without a driver, cancelled without
who/when, completed without its final money).

## Pricing snapshot

Money is integer minor units in `fare_currency`. `fare_rule_id` / `commission_rule_id` / `surge_multiplier` /
`fare_breakdown` and the amounts are copies taken when the trip was priced. Once a trip is completed,
cancelled or no_driver_found they cannot be edited (`Trip::PRICING_COLUMNS`), so later rule edits never change
history. `fare_rule_id` is a foreign key to `fare_rules` (P5-T2; rules are never edited or deleted, see
[fares.md](fares.md)), and `fare_breakdown` is `FareBreakdown::toArray()`. The `commission_rule_id` and
`payment_method_id` columns carry no foreign key yet: their tables arrive with P11-T1 and P6-T1, which add them.

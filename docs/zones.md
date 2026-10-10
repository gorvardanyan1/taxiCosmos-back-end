# Zones (service areas)

A zone is a named service area with a polygon. Fare rules, surge, commission rules and reports hang off
zones, and a pickup must be inside an active zone.

## Data

`zones`: `name`, `code` (unique, never changes: `A-Z 0-9 _ -`, 2-32 characters), `timezone` (IANA, e.g.
`Asia/Yerevan`), `currency` (one of `config('taxikosmos.currencies')`), `is_active`, `priority` (0-1000, higher wins
where zones overlap), and `polygon geometry(MultiPolygon, 4326)` with a **GIST index**. A CHECK constraint
(`ST_IsValid(polygon) AND NOT ST_IsEmpty(polygon)`) means an invalid shape can never be stored, whatever code
writes it. `driver_profiles.home_zone_id` is a foreign key to zones (restricted).

Zones are **never deleted**: the model refuses `delete()`, no route exists, and the foreign key restricts removal
of a zone that something points to. Deactivate instead.

## Admin (permission `zones.manage`)

| Route | What |
| --- | --- |
| `GET /admin/zones` | list + selected zone's polygon (`?zone=<id>`) |
| `POST /admin/zones` | create |
| `PATCH /admin/zones/{id}` | edit name, timezone, currency, priority and/or polygon (the code cannot change) |
| `POST /admin/zones/{id}/deactivate` / `.../activate` | optional `reason`; 409 if already in that state |

Every write is audited (`zone.created`, `zone.updated`, `zone.deactivated`, `zone.activated`); the log holds the
point counts, never the coordinates.

### The polygon payload

`polygon` is **GeoJSON**: a `Polygon`, a `MultiPolygon`, or a `Feature` holding one (also accepted as a JSON string).
Positions are `[longitude, latitude]`, rings are closed (last = first). A Polygon is stored as a MultiPolygon.
Rejected with a validation error on `polygon`:

- not GeoJSON, wrong type, empty, non-numeric positions, longitude outside ±180 or latitude outside ±90
- a ring with fewer than 3 distinct points, or not closed
- more than `ZONE_MAX_VERTICES` points (default 5000)
- anything PostGIS reports as invalid with `ST_IsValid`: **self-intersection** ("Polygon intersects itself."),
  overlapping parts, a hole outside its shell, ...

Until the map editor (P13-T7) lets admins draw, the Zones page takes the polygon as pasted GeoJSON and previews the
real shape. Fare rules on the page arrive with P5-T2.

## Lookup: `App\Services\Zones\ZoneLocator`

```php
$zone = app(ZoneLocator::class)->find(new GeoPoint(latitude: 40.18, longitude: 44.51)); // ?Zone
app(ZoneLocator::class)->serves($point);   // pickup validation
app(ZoneLocator::class)->allAt($point);    // every active zone there, best first
```

Only **active** zones count. The boundary belongs to the zone. Where zones overlap the **highest `priority` wins**;
equal priorities pick the oldest zone, so the answer is stable. The query uses `ST_Covers` with bound coordinates and
the GIST index.

## Timezone

A zone's timezone defines its day. Reports and scheduled surge ask the zone, not the server clock:

```php
$zone->today();              // '2026-10-11' in the zone's timezone
$zone->dayBounds();          // [start, end) of the zone's current day as UTC instants
$zone->dayBounds('2026-03-08'); // a daylight-saving day is 23 or 25 hours
```

Tests: `tests/Feature/Zones/*`, `resources/js/Pages/Zones/*.test.tsx`, `resources/js/lib/geo.test.ts`.

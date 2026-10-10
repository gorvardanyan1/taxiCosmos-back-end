<?php

namespace App\Services\Zones;

use App\Exceptions\ZoneStateException;
use App\Models\User;
use App\Models\Zone;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Create, edit, deactivate and reactivate zones (permission zones.manage). Every change is one
 * transaction with its audit entry. Zones are never deleted. The polygon reaches SQL only as a
 * bound GeoJSON parameter after ZonePolygonValidator accepted it.
 */
final class ZoneService
{
    public function __construct(
        private readonly ZonePolygonValidator $polygons,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, code: string, timezone: string, currency: string, priority?: int, polygon: mixed}  $data
     */
    public function create(User $actor, array $data): Zone
    {
        $geometry = $this->polygons->validate($data['polygon']);

        return DB::transaction(function () use ($actor, $data, $geometry) {
            $id = DB::selectOne(
                'INSERT INTO zones (name, code, timezone, currency, priority, is_active, polygon, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, true, ST_SetSRID(ST_Multi(ST_GeomFromGeoJSON(?)), 4326), now(), now()) RETURNING id',
                [$data['name'], $data['code'], $data['timezone'], $data['currency'], $data['priority'] ?? 0, json_encode($geometry)],
            )->id;

            $zone = Zone::query()->withGeometry()->findOrFail($id);

            $this->audit->record(
                actor: $actor,
                action: 'zone.created',
                target: $zone,
                new: ['name' => $zone->name, 'code' => $zone->code, 'timezone' => $zone->timezone, 'currency' => $zone->currency, 'priority' => $zone->priority, 'polygon_points' => (int) $zone->polygon_points],
            );

            return $zone;
        });
    }

    /**
     * Only the fields present in $data change; the code never does. Nothing changed means no write
     * and no audit entry.
     *
     * @param  array{name?: string, timezone?: string, currency?: string, priority?: int, polygon?: mixed}  $data
     */
    public function update(User $actor, Zone $zone, array $data): Zone
    {
        $geometry = array_key_exists('polygon', $data) ? $this->polygons->validate($data['polygon']) : null;

        return DB::transaction(function () use ($actor, $zone, $data, $geometry) {
            $locked = Zone::query()->withGeometry()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            $old = [];
            $new = [];

            foreach (['name', 'timezone', 'currency', 'priority'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $locked->{$field}) {
                    $old[$field] = $locked->{$field};
                    $new[$field] = $data[$field];
                    $locked->{$field} = $data[$field];
                }
            }

            $polygonChanged = false;

            if ($geometry !== null) {
                $same = DB::selectOne(
                    'SELECT ST_Equals(polygon, ST_SetSRID(ST_Multi(ST_GeomFromGeoJSON(?)), 4326)) AS same FROM zones WHERE id = ?',
                    [json_encode($geometry), $locked->getKey()],
                )->same;

                if (! $same) {
                    $polygonChanged = true;
                    $old['polygon_points'] = (int) $locked->polygon_points;
                    $new['polygon_points'] = $this->pointsOf($geometry);
                }
            }

            if ($old === [] && $new === [] && ! $polygonChanged) {
                return $locked;
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            if ($polygonChanged) {
                DB::update(
                    'UPDATE zones SET polygon = ST_SetSRID(ST_Multi(ST_GeomFromGeoJSON(?)), 4326), updated_at = now() WHERE id = ?',
                    [json_encode($geometry), $locked->getKey()],
                );
            }

            $this->audit->record(actor: $actor, action: 'zone.updated', target: $locked, old: $old, new: $new);

            return Zone::query()->withGeometry()->findOrFail($locked->getKey());
        });
    }

    public function deactivate(User $actor, Zone $zone, ?string $reason = null): Zone
    {
        return $this->setActive($actor, $zone, false, $reason);
    }

    public function activate(User $actor, Zone $zone, ?string $reason = null): Zone
    {
        return $this->setActive($actor, $zone, true, $reason);
    }

    private function setActive(User $actor, Zone $zone, bool $active, ?string $reason): Zone
    {
        return DB::transaction(function () use ($actor, $zone, $active, $reason) {
            $locked = Zone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $active) {
                throw $active ? ZoneStateException::alreadyActive() : ZoneStateException::alreadyInactive();
            }

            $locked->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                actor: $actor,
                action: $active ? 'zone.activated' : 'zone.deactivated',
                target: $locked,
                reason: $reason,
                old: ['is_active' => ! $active],
                new: ['is_active' => $active],
            );

            return $locked;
        });
    }

    /**
     * @param  array{type: string, coordinates: array<int, mixed>}  $geometry
     */
    private function pointsOf(array $geometry): int
    {
        $count = 0;
        array_walk_recursive($geometry['coordinates'], function () use (&$count) {
            $count++;
        });

        return intdiv($count, 2);
    }
}

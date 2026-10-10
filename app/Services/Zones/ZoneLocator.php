<?php

namespace App\Services\Zones;

use App\Data\GeoPoint;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Point-in-polygon lookup: which service zone is a coordinate in? Used to validate pickups and to
 * choose the zone for fares, surge and commission. Only active zones count; the boundary belongs to
 * the zone (ST_Covers); when zones overlap the highest priority wins, the oldest zone breaks a tie.
 * ST_Covers uses the GIST index on the polygon column.
 */
final class ZoneLocator
{
    public function find(GeoPoint $point): ?Zone
    {
        return $this->query($point)->first();
    }

    public function serves(GeoPoint $point): bool
    {
        return $this->query($point)->exists();
    }

    /**
     * Every active zone containing the point, best first (for diagnostics and overlap checks).
     *
     * @return Collection<int, Zone>
     */
    public function allAt(GeoPoint $point): Collection
    {
        return $this->query($point)->get();
    }

    /**
     * @return Builder<Zone>
     */
    private function query(GeoPoint $point): Builder
    {
        return Zone::query()
            ->where('is_active', true)
            ->whereRaw('ST_Covers(zones.polygon, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$point->longitude, $point->latitude])
            ->orderByDesc('priority')
            ->orderBy('id');
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A service zone: fare rules, surge, commission rules and reports hang off it. The polygon column is
 * PostGIS geometry; it is written and read through ZoneService / withGeometry(), never as an
 * attribute (hence hidden and not fillable). Zones are deactivated, never deleted.
 */
#[Fillable(['name', 'timezone', 'currency', 'priority'])]
#[Hidden(['polygon'])]
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true, 'priority' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'priority' => 'integer'];
    }

    protected static function booted(): void
    {
        // Trips, fares, surge and reports keep pointing at a zone for good.
        static::deleting(fn () => throw new LogicException('Zones are deactivated, never deleted.'));
    }

    /**
     * @return HasMany<DriverProfile, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(DriverProfile::class, 'home_zone_id');
    }

    /**
     * Adds the polygon as GeoJSON (polygon_geojson) and its vertex count (polygon_points).
     *
     * @param  Builder<Zone>  $query
     */
    public function scopeWithGeometry(Builder $query): void
    {
        $query->select('zones.*')
            ->selectRaw('ST_AsGeoJSON(zones.polygon, 6) as polygon_geojson')
            ->selectRaw('ST_NPoints(zones.polygon) as polygon_points');
    }

    /**
     * The current date and time in this zone's timezone (for display and "today" logic).
     */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    /**
     * The zone's "today" as a date (Y-m-d): reports and scheduled surge follow the zone's clock, not the server's.
     */
    public function today(): string
    {
        return $this->localNow()->toDateString();
    }

    /**
     * Start (inclusive) and end (exclusive) of a local calendar day in this zone as UTC instants, for
     * querying UTC timestamps. Daylight-saving days are 23 or 25 hours long.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function dayBounds(?string $date = null): array
    {
        $start = CarbonImmutable::parse($date ?? $this->today(), $this->timezone)->startOfDay();

        return [$start->utc(), $start->addDay()->startOfDay()->utc()];
    }
}

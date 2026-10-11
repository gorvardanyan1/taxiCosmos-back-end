<?php

namespace App\Models;

use App\Casts\GeoPointCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The estimate a rider was shown for a pickup and drop-off. It lives for a few minutes
 * (taxikosmos.fares.quote_ttl_minutes) so the trip created from it is priced with the same fare
 * rule and surge the rider saw. `estimates` holds one entry per vehicle class (FareEstimator).
 */
class FareQuote extends Model
{
    use HasUuids;
    use Prunable;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pickup_location' => GeoPointCast::class,
            'dropoff_location' => GeoPointCast::class,
            'distance_meters' => 'integer',
            'duration_seconds' => 'integer',
            'estimates' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Expired quotes are useless after a day; the scheduler deletes them (`model:prune`).
     *
     * @return Builder<FareQuote>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}

<?php

namespace App\Models;

use App\Enums\AppClient;
use App\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone that can receive pushes for one user in one app (P8-T4). The push token is a secret-ish identifier: it is never
 * logged, returned by the API or shown in the admin. Rows are never deleted by the API, only revoked.
 *
 * @property int $id
 * @property int $user_id
 * @property AppClient $app
 * @property DevicePlatform $platform
 * @property string $push_token
 * @property string|null $device_id
 */
class Device extends Model
{
    protected $hidden = ['push_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['app' => AppClient::class, 'platform' => DevicePlatform::class, 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Device>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

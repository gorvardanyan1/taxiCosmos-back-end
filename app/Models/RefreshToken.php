<?php

namespace App\Models;

use App\Enums\AppClient;
use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['app' => AppClient::class, 'expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}

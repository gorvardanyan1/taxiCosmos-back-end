<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * What a mutating mobile call answered, kept under the caller's Idempotency-Key (see the `idempotent` middleware). Only answers that
 * succeeded are kept; a key is useful for a day (a retry comes within minutes), then the scheduler deletes the row (`model:prune`).
 *
 * @property int $user_id
 * @property string $key
 * @property string $fingerprint
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 */
class ApiIdempotencyKey extends Model
{
    use Prunable;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response_status' => 'integer', 'response_body' => 'array'];
    }

    /**
     * @return Builder<ApiIdempotencyKey>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay());
    }

    public function isFinished(): bool
    {
        return $this->response_status !== null;
    }
}

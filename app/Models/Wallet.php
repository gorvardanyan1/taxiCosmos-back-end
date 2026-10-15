<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rider's platform balance in one currency (P6-T1). `balance` (integer minor units) is a cached total of the ledger: it changes only
 * through WalletLedger, in the same transaction that inserts the ledger row, and `wallets:reconcile` re-checks it every night.
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $attributes = ['balance' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}

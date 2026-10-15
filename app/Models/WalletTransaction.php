<?php

namespace App\Models;

use App\Enums\WalletEntryType;
use App\Enums\WalletReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a wallet's ledger (P6-T1). Append-only: a row is inserted by WalletLedger and never changed or removed. A mistake is
 * corrected by a new, opposite entry. This model refuses an update or a delete, and so does the database (a trigger).
 */
class WalletTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A wallet ledger entry is never changed. Post an opposite entry instead.'));
        static::deleting(fn () => throw new LogicException('A wallet ledger entry is never removed. Post an opposite entry instead.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletEntryType::class,
            'reason' => WalletReason::class,
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

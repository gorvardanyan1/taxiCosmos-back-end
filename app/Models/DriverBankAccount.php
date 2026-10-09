<?php

namespace App\Models;

use App\Exceptions\DeletedRecordException;
use Database\Factories\DriverBankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A driver's payout account (P6-T8). The account number is encrypted; account_last4 is
 * kept alongside for masked display. At most one default account per driver is enforced
 * by a partial unique index.
 */
#[Fillable(['account_holder', 'account_number', 'bank_name', 'swift'])]
#[Hidden(['account_number'])]
class DriverBankAccount extends Model
{
    /** @use HasFactory<DriverBankAccountFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Encrypts the number and derives account_last4 in the same assignment.
     *
     * @return Attribute<string, string>
     */
    protected function accountNumber(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => Crypt::decryptString($value),
            set: function (string $value) {
                $normalised = preg_replace('/\s+/', '', $value);

                return [
                    'account_number' => Crypt::encryptString($normalised),
                    'account_last4' => substr($normalised, -4),
                ];
            },
        );
    }

    /**
     * @return BelongsTo<DriverProfile, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    /**
     * Make this the driver's only default payout account (driver row locked, see Vehicle::markAsPrimary).
     */
    public function markAsDefault(): void
    {
        DB::transaction(function () {
            DriverProfile::query()->whereKey($this->driver_id)->lockForUpdate()->first();

            // Re-read under the lock: a removed (soft-deleted) row must never take the flag,
            // or the driver would be left without a usable one.
            if (static::query()->whereKey($this->getKey())->doesntExist()) {
                throw DeletedRecordException::for('bank account');
            }

            static::query()
                ->where('driver_id', $this->driver_id)
                ->whereKeyNot($this->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }
}

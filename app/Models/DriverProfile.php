<?php

namespace App\Models;

use App\Casts\EncryptedWithBlindIndex;
use App\Enums\DriverAvailability;
use App\Enums\DriverVerificationStatus;
use App\Observers\DriverProfileObserver;
use App\Support\BlindIndex;
use App\Support\LicenseNumber;
use Database\Factories\DriverProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Driver-side data of a user (is_driver). Verification, availability and ratings are
 * changed by services, never mass-assigned from driver input.
 */
#[Fillable(['license_number', 'license_expiry', 'home_zone_id'])]
#[Hidden(['license_number', 'license_number_hash'])]
#[ObservedBy([DriverProfileObserver::class])]
class DriverProfile extends Model
{
    /** @use HasFactory<DriverProfileFactory> */
    use HasFactory;

    protected $attributes = [
        'verification_status' => DriverVerificationStatus::Pending->value,
        'availability' => DriverAvailability::Offline->value,
        'rating_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'license_number' => EncryptedWithBlindIndex::class.':license_number_hash,'.LicenseNumber::class,
            'license_expiry' => 'date',
            'verification_status' => DriverVerificationStatus::class,
            'approved_at' => 'datetime',
            'availability' => DriverAvailability::class,
            'last_online_at' => 'datetime',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'driver_id');
    }

    /**
     * @return HasOne<Vehicle, $this>
     */
    public function primaryVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'driver_id')->where('is_primary', true);
    }

    /**
     * @return HasMany<DriverDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class, 'driver_id');
    }

    /**
     * @return HasMany<DriverBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(DriverBankAccount::class, 'driver_id');
    }

    /**
     * @return HasOne<DriverBankAccount, $this>
     */
    public function defaultBankAccount(): HasOne
    {
        return $this->hasOne(DriverBankAccount::class, 'driver_id')->where('is_default', true);
    }

    /**
     * Exact license lookup through the blind index (the column itself is encrypted),
     * ignoring case and whitespace like storage does.
     */
    #[Scope]
    protected function whereLicenseNumber(Builder $query, string $licenseNumber): void
    {
        $query->where('license_number_hash', app(BlindIndex::class)->hash(LicenseNumber::normalize($licenseNumber)));
    }
}

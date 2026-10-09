<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\EncryptedWithBlindIndex;
use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Support\BlindIndex;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * One row per person. Capability flags (is_rider, is_driver, is_admin) let the same
 * account ride and drive; admin-tier access additionally requires a spatie role.
 * Flags, status and ratings are not mass-assignable — services change them explicitly.
 */
#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'phone', 'phone_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $attributes = [
        'is_rider' => false,
        'is_driver' => false,
        'is_admin' => false,
        'status' => UserStatus::Active->value,
        'rider_rating_count' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phone' => EncryptedWithBlindIndex::class.':phone_hash',
            'is_rider' => 'boolean',
            'is_driver' => 'boolean',
            'is_admin' => 'boolean',
            'status' => UserStatus::class,
            'rider_rating_avg' => 'decimal:2',
            'rider_rating_count' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Driver-side data; present once the user has applied to drive (P4-T4).
     *
     * @return HasOne<DriverProfile, $this>
     */
    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    /**
     * Exact phone lookup through the blind index (the phone column itself is encrypted).
     * Pass the E.164-normalised number.
     */
    #[Scope]
    protected function wherePhone(Builder $query, string $phone): void
    {
        $query->where('phone_hash', app(BlindIndex::class)->hash($phone));
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Add rider capability to this account (no-op if already a rider).
     */
    public function enableRiderMode(): void
    {
        $this->forceFill(['is_rider' => true])->save();
    }

    /**
     * Add driver capability to this account (no-op if already a driver).
     */
    public function enableDriverMode(): void
    {
        $this->forceFill(['is_driver' => true])->save();
    }

    /**
     * Super admins bypass every permission check (Gate::before in AppServiceProvider),
     * but only while they still have admin access (flag + active status).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasAdminAccess() && $this->hasRole(AdminRole::SuperAdmin->value);
    }

    /**
     * Admin-tier access: the is_admin flag alone is never enough — the account must
     * also be active and hold at least one admin role.
     */
    public function hasAdminAccess(): bool
    {
        return $this->is_admin
            && $this->isActive()
            && $this->hasAnyRole(AdminRole::values());
    }
}

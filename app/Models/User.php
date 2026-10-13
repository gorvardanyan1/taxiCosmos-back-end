<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\EncryptedWithBlindIndex;
use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Observers\UserObserver;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use App\Support\Locales;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * One row per person. Capability flags (is_rider, is_driver, is_admin) let the same
 * account ride and drive; admin-tier access additionally requires a spatie role.
 * Flags, status and ratings are not mass-assignable — services change them explicitly.
 */
#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'phone', 'phone_hash', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

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
            'phone' => EncryptedWithBlindIndex::class.':phone_hash,'.E164PhoneNumber::class,
            'is_rider' => 'boolean',
            'is_driver' => 'boolean',
            'is_admin' => 'boolean',
            'status' => UserStatus::class,
            'rider_rating_avg' => 'decimal:2',
            'rider_rating_count' => 'integer',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
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
     * Exact phone lookup through the blind index (the phone column itself is encrypted). The input
     * is normalised to E.164 first, so any way of writing the number finds the account; input that
     * is not a phone number matches nothing.
     */
    #[Scope]
    protected function wherePhone(Builder $query, string $phone): void
    {
        $normalised = E164PhoneNumber::tryNormalize($phone);

        $normalised === null
            ? $query->whereRaw('false')
            : $query->where('phone_hash', app(BlindIndex::class)->hash($normalised));
    }

    /**
     * Mail and notifications (password reset) go out in the user's language.
     */
    public function preferredLocale(): ?string
    {
        return Locales::forUser($this);
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

    /**
     * Whether one of this account's roles must have two-factor authentication (config security.two_factor.required_roles).
     */
    public function requiresTwoFactor(): bool
    {
        $roles = config('security.two_factor.required_roles');

        return $roles !== [] && $this->hasAnyRole($roles);
    }

    /**
     * A secret exists (the admin started the setup) but no code from their app has been entered yet.
     */
    public function hasPendingTwoFactorSetup(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }

    /**
     * How many recovery codes are still unused (0 when two-factor is off).
     */
    public function recoveryCodesLeft(): int
    {
        return $this->two_factor_recovery_codes === null ? 0 : count($this->recoveryCodes());
    }

    /**
     * A recovery code works once: it is removed from the set (so "N codes left" is true), under a row lock so two
     * simultaneous sign-ins with the same code cannot both pass. Fortify's own version swaps in a fresh code
     * unseen by the admin and has no lock.
     *
     * @param  string  $code
     */
    public function replaceRecoveryCode($code): void
    {
        DB::transaction(function () use ($code) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $codes = $locked->two_factor_recovery_codes === null ? [] : $locked->recoveryCodes();

            if (! in_array($code, $codes, true)) {
                throw ValidationException::withMessages(['recovery_code' => __('The provided two factor recovery code was invalid.')]);
            }

            $left = array_values(array_diff($codes, [$code]));
            $locked->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($left, JSON_THROW_ON_ERROR))])->save();
            $this->two_factor_recovery_codes = $locked->two_factor_recovery_codes;
        });

        RecoveryCodeReplaced::dispatch($this, $code);
    }
}

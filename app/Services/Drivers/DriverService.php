<?php

namespace App\Services\Drivers;

use App\Enums\DriverAvailability;
use App\Enums\TripStatus;
use App\Enums\UserStatus;
use App\Exceptions\DriverStateException;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\E164PhoneNumber;
use App\Support\LicenseNumber;
use Illuminate\Support\Facades\DB;

/**
 * What admins do to a driver: edit contact and licence details (drivers.edit) and suspend /
 * reactivate (drivers.suspend). Each action is one transaction with its audit entry and a required
 * reason, and takes the driver's row lock first (the same lock matching and availability use), so it
 * cannot race a trip being accepted.
 *
 * Suspension is a property of the account (users.status), exactly like a rider's: a person who is both
 * a driver and a rider is suspended as a whole. Suspending revokes every mobile session, takes the
 * driver offline, and is refused while the driver is on a live trip.
 */
final class DriverService
{
    /** The trip statuses in which a driver is committed to a rider. */
    private const LIVE_TRIP_STATUSES = [TripStatus::Matched, TripStatus::Arrived, TripStatus::InProgress];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Only the fields present in $data change. Returns false when nothing differed (no write, no audit entry).
     *
     * @param  array{name?: string, email?: ?string, phone?: string, license_number?: string, license_expiry?: string, home_zone_id?: ?int}  $data
     */
    public function update(User $actor, DriverProfile $driver, array $data, string $reason): bool
    {
        return DB::transaction(function () use ($actor, $driver, $data, $reason) {
            [$locked, $user] = $this->lock($driver);
            $old = [];
            $new = [];

            foreach (['name', 'email'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $user->{$field}) {
                    $old[$field] = $user->{$field};
                    $new[$field] = $data[$field];
                    $user->{$field} = $data[$field];
                }
            }

            // The phone is stored in E.164; "091 440 221" and "+37491440221" are the same number.
            if (array_key_exists('phone', $data)) {
                $phone = E164PhoneNumber::normalize($data['phone']);

                if ($phone !== $user->phone) {
                    $old['phone'] = $user->phone;
                    $new['phone'] = $phone;
                    $user->phone = $phone;
                }
            }

            // Licences compare ignoring case and spaces, like storage does.
            if (array_key_exists('license_number', $data) && LicenseNumber::normalize($data['license_number']) !== LicenseNumber::normalize((string) $locked->license_number)) {
                $old['license_number'] = $locked->license_number;
                $new['license_number'] = $data['license_number'];
                $locked->license_number = $data['license_number'];
            }

            foreach (['license_expiry' => fn ($value) => $value?->toDateString(), 'home_zone_id' => fn ($value) => $value] as $field => $read) {
                if (array_key_exists($field, $data) && $data[$field] !== $read($locked->{$field})) {
                    $old[$field] = $read($locked->{$field});
                    $new[$field] = $data[$field];
                    $locked->{$field} = $data[$field];
                }
            }

            if ($old === []) {
                return false;
            }

            $user->isDirty() && $user->save();
            $locked->isDirty() && $locked->save();

            $this->audit->record(actor: $actor, action: 'driver.updated', target: $locked, reason: $reason, old: $old, new: $new, reasonRequired: true);

            return true;
        });
    }

    public function suspend(User $actor, DriverProfile $driver, string $reason): DriverProfile
    {
        return DB::transaction(function () use ($actor, $driver, $reason) {
            [$locked, $user] = $this->lock($driver);

            if ($user->is($actor)) {
                throw DriverStateException::ownAccount();
            }

            // An admin who also drives is suspended from Settings → Users, not from the driver list.
            if ($user->is_admin) {
                throw DriverStateException::adminAccount();
            }

            if ($user->status !== UserStatus::Active) {
                throw DriverStateException::notActive();
            }

            if (Trip::query()->where('driver_id', $locked->id)->whereIn('status', array_map(fn (TripStatus $s) => $s->value, self::LIVE_TRIP_STATUSES))->exists()) {
                throw DriverStateException::onTrip();
            }

            // The model save publishes user.suspended after commit and revokes the mobile sessions (UserObserver).
            $user->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => trim($reason)])->save();
            $user->tokens()->delete();

            if ($locked->availability !== DriverAvailability::Offline) {
                $locked->forceFill(['availability' => DriverAvailability::Offline])->save();
            }

            $this->audit->record(
                actor: $actor, action: 'driver.suspended', target: $locked, reason: $reason, reasonRequired: true,
                old: ['status' => UserStatus::Active->value], new: ['status' => UserStatus::Suspended->value],
            );

            return $locked;
        });
    }

    public function reactivate(User $actor, DriverProfile $driver, string $reason): DriverProfile
    {
        return DB::transaction(function () use ($actor, $driver, $reason) {
            [$locked, $user] = $this->lock($driver);

            if ($user->status !== UserStatus::Suspended) {
                throw DriverStateException::notSuspended();
            }

            $user->forceFill(['status' => UserStatus::Active, 'suspension_reason' => null])->save();

            $this->audit->record(
                actor: $actor, action: 'driver.reactivated', target: $locked, reason: $reason, reasonRequired: true,
                old: ['status' => UserStatus::Suspended->value], new: ['status' => UserStatus::Active->value],
            );

            return $locked;
        });
    }

    /**
     * The driver profile and its user, both locked (profile first, the same order everywhere).
     *
     * @return array{DriverProfile, User}
     */
    private function lock(DriverProfile $driver): array
    {
        $locked = DriverProfile::query()->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
        $user = User::query()->withTrashed()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

        return [$locked, $user];
    }
}

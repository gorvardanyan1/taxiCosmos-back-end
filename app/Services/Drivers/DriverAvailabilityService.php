<?php

namespace App\Services\Drivers;

use App\Enums\DriverAvailability;
use App\Enums\VehicleStatus;
use App\Exceptions\DriverStatusException;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The driver's online / offline switch (POST /api/v1/driver/availability). The profile is changed through the model, so the
 * `driver.availability_changed` real-time event is published (after commit) by DriverProfileObserver. The row is locked, so
 * two quick taps serialise and only the first one changes anything.
 */
final class DriverAvailabilityService
{
    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly DriverLocationStore $locations,
    ) {}

    /**
     * Go online, or stay as they are when they already are (online, or on a trip).
     *
     * @throws DriverStatusException suspended, not verified, expired documents, or no active primary vehicle
     */
    public function goOnline(User $user): DriverProfile
    {
        return DB::transaction(function () use ($user) {
            $profile = $this->lock($user);

            if ($profile->availability !== DriverAvailability::Offline) {
                return $profile;
            }

            if (! $user->isActive()) {
                throw DriverStatusException::suspended();
            }

            $this->eligibility->assertVerified($profile);

            $hasVehicle = $profile->vehicles()->where('is_primary', true)->where('status', VehicleStatus::Active->value)->exists();
            if (! $hasVehicle) {
                throw DriverStatusException::noActiveVehicle();
            }

            $profile->forceFill(['availability' => DriverAvailability::Online, 'last_online_at' => now()])->save();

            // A position left from an earlier session must not pass for where the driver is now.
            DB::afterCommit(fn () => $this->locations->removeQuietly($profile->user_id));

            return $profile;
        });
    }

    /**
     * Go offline: refused during a trip, a no-op when already offline.
     *
     * @throws DriverStatusException when the driver is on a trip
     */
    public function goOffline(User $user): DriverProfile
    {
        return DB::transaction(function () use ($user) {
            $profile = $this->lock($user);

            if ($profile->availability === DriverAvailability::OnTrip) {
                throw DriverStatusException::onTrip();
            }

            if ($profile->availability === DriverAvailability::Online) {
                $profile->forceFill(['availability' => DriverAvailability::Offline])->save();
                DB::afterCommit(fn () => $this->locations->removeQuietly($profile->user_id));
            }

            return $profile;
        });
    }

    private function lock(User $user): DriverProfile
    {
        return DriverProfile::query()->where('user_id', $user->getKey())->lockForUpdate()->first()
            ?? throw DriverStatusException::notVerified();
    }
}

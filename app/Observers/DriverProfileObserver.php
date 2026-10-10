<?php

namespace App\Observers;

use App\Events\Realtime\DriverAvailabilityChanged;
use App\Events\Realtime\DriverVerificationChanged;
use App\Models\DriverProfile;
use BackedEnum;

/**
 * Publishes driver state changes to the real-time stream. Changes made with raw query-builder
 * updates bypass Eloquent events, so services must change drivers through the model.
 */
class DriverProfileObserver
{
    public function updated(DriverProfile $profile): void
    {
        if ($profile->wasChanged('verification_status')) {
            DriverVerificationChanged::dispatch(
                $profile->user_id,
                $profile->getKey(),
                $this->value($profile->getOriginal('verification_status')),
                $this->value($profile->verification_status),
            );
        }

        if ($profile->wasChanged('availability')) {
            DriverAvailabilityChanged::dispatch(
                $profile->user_id,
                $profile->getKey(),
                $this->value($profile->getOriginal('availability')),
                $this->value($profile->availability),
            );
        }
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}

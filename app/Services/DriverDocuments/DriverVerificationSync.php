<?php

namespace App\Services\DriverDocuments;

use App\Enums\DriverVerificationStatus;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Brings driver_profiles.verification_status in line with the driver's documents. The profile is
 * saved through the model so the real-time DriverVerificationChanged event fires (after commit).
 */
final class DriverVerificationSync
{
    public function __construct(private readonly VerificationResolver $resolver) {}

    /**
     * Call inside the transaction that changed the documents. The profile row is locked so two
     * simultaneous reviews of the same driver serialise and the last one sees both decisions.
     */
    public function sync(DriverProfile $profile, ?User $reviewer): DriverProfile
    {
        return DB::transaction(function () use ($profile, $reviewer) {
            $locked = DriverProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $status = $this->resolver->resolve($locked);
            $previous = $locked->verification_status;

            if ($status === $previous) {
                return $locked;
            }

            $approved = $status === DriverVerificationStatus::Approved;

            $locked->forceFill([
                'verification_status' => $status,
                'approved_by' => $approved ? $reviewer?->getKey() : null,
                'approved_at' => $approved ? now() : null,
            ])->save();

            if ($reviewer !== null) {
                activity('driver-documents')
                    ->performedOn($locked)
                    ->causedBy($reviewer)
                    ->event('verification_changed')
                    ->withProperties([
                        'old' => ['verification_status' => $previous->value],
                        'new' => ['verification_status' => $status->value],
                    ])
                    ->log("Driver verification changed to {$status->value}");
            }

            return $locked;
        });
    }
}

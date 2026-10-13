<?php

namespace App\Services\Drivers;

use App\Enums\DriverVerificationStatus;
use App\Exceptions\DriverStatusException;
use App\Models\DriverProfile;
use App\Services\DriverDocuments\VerificationResolver;

/** What a driver must be to go online or to report a position: approved, with no required document past its expiry. */
final class DriverEligibility
{
    public function __construct(private readonly VerificationResolver $documents) {}

    /**
     * @throws DriverStatusException
     */
    public function assertVerified(DriverProfile $profile): void
    {
        match ($profile->verification_status) {
            DriverVerificationStatus::Approved => null,
            DriverVerificationStatus::Expired => throw DriverStatusException::documentsExpired(),
            default => throw DriverStatusException::notVerified(),
        };

        // The stored status can still say approved on the day after a document expired.
        if ($this->documents->hasExpiredDocument($profile)) {
            throw DriverStatusException::documentsExpired();
        }
    }
}

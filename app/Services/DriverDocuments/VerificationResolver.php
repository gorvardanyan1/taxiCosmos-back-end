<?php

namespace App\Services\DriverDocuments;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverVerificationStatus;
use App\Models\DriverDocument;
use App\Models\DriverProfile;

/**
 * Works out a driver's verification status from their documents:
 *
 *  - approved: every required type has an approved document that has not expired;
 *  - otherwise, looking at the newest document of each type that is not covered:
 *    rejected if any was rejected, else expired if any expired, else pending
 *    (a missing or freshly uploaded document waits for review).
 */
final class VerificationResolver
{
    public function __construct(private readonly RequiredDocuments $required) {}

    public function resolve(DriverProfile $profile): DriverVerificationStatus
    {
        $documents = $profile->documents()->orderByDesc('id')->get()->groupBy(fn (DriverDocument $d) => $d->type->value);

        $allCovered = true;
        $rejected = false;
        $expired = false;

        foreach ($this->required->types() as $type) {
            $ofType = $documents->get($type->value, collect());

            if ($ofType->contains(fn (DriverDocument $d) => $this->isValid($d))) {
                continue;
            }

            $allCovered = false;
            $newest = $ofType->first();
            $rejected = $rejected || $newest?->status === DriverDocumentStatus::Rejected;
            $expired = $expired || ($newest !== null && $this->isExpired($newest));
        }

        return match (true) {
            $allCovered => DriverVerificationStatus::Approved,
            $rejected => DriverVerificationStatus::Rejected,
            $expired => DriverVerificationStatus::Expired,
            default => DriverVerificationStatus::Pending,
        };
    }

    private function isValid(DriverDocument $document): bool
    {
        return $document->status === DriverDocumentStatus::Approved && ! $document->isPastExpiry();
    }

    private function isExpired(DriverDocument $document): bool
    {
        return $document->status === DriverDocumentStatus::Expired
            || ($document->status === DriverDocumentStatus::Approved && $document->isPastExpiry());
    }
}

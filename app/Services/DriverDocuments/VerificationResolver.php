<?php

namespace App\Services\DriverDocuments;

use App\Enums\DocumentRequirementState;
use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
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

    /**
     * The required documents that are not yet covered by a valid approved one, with where each stands: nothing uploaded,
     * waiting for review, rejected (with the reviewer's reason) or run out. Empty when the driver is fully covered.
     *
     * @return list<array{type: DriverDocumentType, state: DocumentRequirementState, reason: ?string}>
     */
    public function outstanding(DriverProfile $profile): array
    {
        $documents = $profile->documents()->orderByDesc('id')->get()->groupBy(fn (DriverDocument $d) => $d->type->value);
        $outstanding = [];

        foreach ($this->required->types() as $type) {
            $ofType = $documents->get($type->value, collect());

            if ($ofType->contains(fn (DriverDocument $d) => $this->isValid($d))) {
                continue;
            }

            $newest = $ofType->first();
            $state = match (true) {
                $newest === null => DocumentRequirementState::Missing,
                $newest->status === DriverDocumentStatus::Rejected => DocumentRequirementState::Rejected,
                $this->isExpired($newest) => DocumentRequirementState::Expired,
                default => DocumentRequirementState::Pending,
            };

            $outstanding[] = ['type' => $type, 'state' => $state, 'reason' => $state === DocumentRequirementState::Rejected ? $newest->rejection_reason : null];
        }

        return $outstanding;
    }

    /**
     * Whether a required document the driver has uploaded has run out (by date or status) without a valid replacement of the
     * same type. The stored verification status can lag behind a document's expiry date until the expiry job runs, so the
     * places that must be strict (going online) ask this directly. A type with no upload at all is not "expired".
     */
    public function hasExpiredDocument(DriverProfile $profile): bool
    {
        $documents = $profile->documents()->orderByDesc('id')->get()->groupBy(fn (DriverDocument $d) => $d->type->value);

        foreach ($this->required->types() as $type) {
            $ofType = $documents->get($type->value, collect());

            if ($ofType->isEmpty() || $ofType->contains(fn (DriverDocument $d) => $this->isValid($d))) {
                continue;
            }

            if ($this->isExpired($ofType->first())) {
                return true;
            }
        }

        return false;
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

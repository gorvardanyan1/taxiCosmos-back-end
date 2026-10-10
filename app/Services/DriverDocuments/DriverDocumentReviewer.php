<?php

namespace App\Services\DriverDocuments;

use App\Enums\DriverDocumentStatus;
use App\Exceptions\DocumentReviewException;
use App\Models\DriverDocument;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Approve / reject a pending driver document (admin permission drivers.verify). Each decision is
 * made under a row lock, logged to the activity log with the reviewer, and re-evaluates the
 * driver's verification status in the same transaction.
 */
final class DriverDocumentReviewer
{
    public function __construct(
        private readonly DriverVerificationSync $verification,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(DriverDocument $document, User $reviewer): DriverDocument
    {
        return $this->review($document, $reviewer, DriverDocumentStatus::Approved, null);
    }

    public function reject(DriverDocument $document, User $reviewer, string $reason): DriverDocument
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A rejection needs a reason.');
        }

        return $this->review($document, $reviewer, DriverDocumentStatus::Rejected, $reason);
    }

    private function review(DriverDocument $document, User $reviewer, DriverDocumentStatus $decision, ?string $reason): DriverDocument
    {
        return DB::transaction(function () use ($document, $reviewer, $decision, $reason) {
            // Lock order is document, then the driver profile (inside the verification sync), here and
            // in the uploader, so concurrent reviews cannot deadlock.
            $locked = DriverDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeReviewed()) {
                throw DocumentReviewException::notPending();
            }

            if ($decision === DriverDocumentStatus::Approved && $locked->isPastExpiry()) {
                throw DocumentReviewException::expired();
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            // The document number is never logged (AuditRedactor / docs/security.md).
            $this->audit->record(
                actor: $reviewer,
                action: "driver.document.{$decision->value}",
                target: $locked,
                reason: $reason,
                old: ['status' => DriverDocumentStatus::Pending->value],
                new: ['status' => $decision->value],
                context: ['driver_id' => $locked->driver_id, 'type' => $locked->type->value],
                reasonRequired: $decision === DriverDocumentStatus::Rejected,
            );

            $this->verification->sync($locked->driver, $reviewer);

            return $locked;
        });
    }
}

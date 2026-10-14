<?php

namespace App\Events;

use App\Enums\DriverDocumentStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admin approved or rejected a driver's document. Raised after the review commits (so the push is never sent for a rolled
 * back decision); the driver gets a push (P8-T4).
 */
class DriverDocumentReviewed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $driverUserId,
        public readonly int $documentId,
        public readonly DriverDocumentStatus $decision,
    ) {}
}

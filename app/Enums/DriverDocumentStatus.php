<?php

namespace App\Enums;

enum DriverDocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * Only a pending document can be reviewed; approved, rejected and expired are final
     * (a driver replaces a document by uploading a new one).
     */
    public function canBeReviewed(): bool
    {
        return $this === self::Pending;
    }
}

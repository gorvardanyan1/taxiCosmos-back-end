<?php

namespace App\Exceptions;

use DomainException;

/**
 * A review action the document's current state does not allow (HTTP 409).
 */
class DocumentReviewException extends DomainException
{
    public static function notPending(): self
    {
        return new self('This document has already been reviewed.');
    }

    public static function expired(): self
    {
        return new self('This document has expired and cannot be approved. The driver must upload a new one.');
    }
}

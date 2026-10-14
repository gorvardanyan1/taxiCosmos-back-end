<?php

namespace App\Exceptions;

use RuntimeException;

/** The push service could not be used right now (down, rate limited, refused the request). The job retries. */
class PushDeliveryException extends RuntimeException
{
    public static function unavailable(string $provider, ?int $status = null): self
    {
        // Never the response body or the request: they can echo tokens.
        return new self("The {$provider} push service is not available".($status === null ? '' : " (HTTP {$status})").'.');
    }
}
